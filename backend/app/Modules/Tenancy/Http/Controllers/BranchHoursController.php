<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Identity\Access\TenantAccess;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\BranchOpeningHour;
use App\Modules\Tenancy\Models\BranchScheduleException;
use App\Modules\Tenancy\Schedule\OpeningSchedule;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class BranchHoursController
{
    private const TIME = 'date_format:H:i';

    public function show(Request $request, Branch $branch, TenantAccess $access, TenantContext $context): JsonResponse
    {
        $this->guard($request, $branch, $access);

        return new JsonResponse(['data' => $this->present($branch, $context)]);
    }

    /**
     * Substitui o horário semanal e as exceções a partir de hoje.
     */
    public function update(Request $request, Branch $branch, TenantAccess $access, TenantContext $context): JsonResponse
    {
        $this->guard($request, $branch, $access);

        $data = $request->validate([
            'weekly' => ['present', 'array', 'max:28'],
            'weekly.*.weekday' => ['required', 'integer', 'between:0,6'],
            'weekly.*.opens_at' => ['required', self::TIME],
            'weekly.*.closes_at' => ['required', self::TIME],
            'exceptions' => ['sometimes', 'array', 'max:100'],
            'exceptions.*.date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'exceptions.*.closed' => ['required', 'boolean'],
            'exceptions.*.opens_at' => ['required_if:exceptions.*.closed,false', 'nullable', self::TIME],
            'exceptions.*.closes_at' => ['required_if:exceptions.*.closed,false', 'nullable', self::TIME],
            'exceptions.*.note' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $today = CarbonImmutable::now($context->get()->timezone)->toDateString();

        DB::transaction(function () use ($branch, $data, $today) {
            BranchOpeningHour::query()->where('branch_id', $branch->id)->delete();
            foreach ($data['weekly'] as $row) {
                BranchOpeningHour::query()->create([
                    'branch_id' => $branch->id,
                    'weekday' => $row['weekday'],
                    'opens_at' => $row['opens_at'],
                    'closes_at' => $row['closes_at'],
                ]);
            }

            if (array_key_exists('exceptions', $data)) {
                BranchScheduleException::query()->where('branch_id', $branch->id)->where('date', '>=', $today)->delete();
                foreach ($data['exceptions'] as $row) {
                    BranchScheduleException::query()->create([
                        'branch_id' => $branch->id,
                        'date' => $row['date'],
                        'closed' => $row['closed'],
                        'opens_at' => $row['closed'] ? null : $row['opens_at'],
                        'closes_at' => $row['closed'] ? null : $row['closes_at'],
                        'note' => $row['note'] ?? null,
                    ]);
                }
            }
        });

        return new JsonResponse(['data' => $this->present($branch, $context)]);
    }

    /** @return array<string, mixed> */
    private function present(Branch $branch, TenantContext $context): array
    {
        $tz = $context->get()->timezone;
        $today = CarbonImmutable::now($tz)->toDateString();

        return [
            'branch_id' => $branch->public_id,
            'timezone' => $tz,
            'weekly' => BranchOpeningHour::query()->where('branch_id', $branch->id)->orderBy('weekday')->orderBy('opens_at')->get()
                ->map(fn (BranchOpeningHour $h) => [
                    'weekday' => $h->weekday,
                    'opens_at' => substr($h->opens_at, 0, 5),
                    'closes_at' => substr($h->closes_at, 0, 5),
                ])->values(),
            'exceptions' => BranchScheduleException::query()->where('branch_id', $branch->id)->where('date', '>=', $today)->orderBy('date')->get()
                ->map(fn (BranchScheduleException $e) => [
                    'date' => $e->date->toDateString(),
                    'closed' => $e->closed,
                    'opens_at' => $e->opens_at !== null ? substr($e->opens_at, 0, 5) : null,
                    'closes_at' => $e->closes_at !== null ? substr($e->closes_at, 0, 5) : null,
                    'note' => $e->note,
                ])->values(),
            'status' => OpeningSchedule::forBranch($branch, $tz)->status(CarbonImmutable::now()),
        ];
    }

    private function guard(Request $request, Branch $branch, TenantAccess $access): void
    {
        /** @var User $user */
        $user = $request->user();
        if (! $access->for($user)->canAccessBranch($branch->id)) {
            throw new NotFoundHttpException;
        }
    }
}
