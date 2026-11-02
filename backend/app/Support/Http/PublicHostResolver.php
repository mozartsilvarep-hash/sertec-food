<?php

namespace App\Support\Http;

/**
 * Resolve um nome de host para um IP público (porta para testes). Usado antes
 * de buscar URLs informadas por clientes: bloqueia rede interna, loopback e
 * faixas reservadas (SSRF), e o IP devolvido é fixado na conexão para evitar
 * troca de DNS entre a checagem e o acesso (DNS rebinding).
 */
interface PublicHostResolver
{
    /** IP público do host, ou null se não resolver ou se cair em faixa privada/reservada. */
    public function resolve(string $host): ?string;
}
