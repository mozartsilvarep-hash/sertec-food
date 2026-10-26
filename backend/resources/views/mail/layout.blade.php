<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $title ?? 'SerTec Food' }}</title>
</head>
<body style="margin:0;background:#f6f3ef;font-family:Arial,Helvetica,sans-serif;color:#1c1917;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f3ef;padding:24px 12px;">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:12px;border:1px solid #e7e2dc;">
<tr><td style="padding:28px 28px 8px;font-size:13px;letter-spacing:.06em;text-transform:uppercase;color:#c2410c;font-weight:bold;">SerTec Food</td></tr>
<tr><td style="padding:0 28px 28px;font-size:16px;line-height:1.55;">
@yield('content')
</td></tr>
</table>
<p style="max-width:520px;margin:16px auto 0;font-size:12px;color:#78716c;line-height:1.5;">Mensagem automática enviada por SerTec Solution. Não responda este e-mail.</p>
</td></tr>
</table>
</body>
</html>
