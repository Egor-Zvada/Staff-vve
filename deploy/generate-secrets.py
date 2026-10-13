from pathlib import Path
import json, secrets
destination = Path('private-runtime')
destination.mkdir(exist_ok=True)
file = destination / 'bootstrap-secrets.json'
if file.exists():
    raise SystemExit('Existing secrets retained; generator does not overwrite credentials.')
data = dict(app_secret=secrets.token_hex(32), db_password=secrets.token_hex(24), admin_password=secrets.token_urlsafe(21), enrollment_pin=str(secrets.randbelow(900000)+100000), owner_login='owner', owner_password=secrets.token_urlsafe(24), private_path='/service-'+secrets.token_hex(18))
file.write_text(json.dumps(data,indent=2),encoding='utf-8')
access = '# Staff V2 — реквизиты владельца\n\nНе публиковать и не отправлять в обычную справку.\n\n'
access += 'Сайт: https://192.168.110.202/\n\nАдминка: https://192.168.110.202/login\n\nТехнический ID: 0\nПароль: '+data['admin_password']+'\nPIN выдачи NFC: '+data['enrollment_pin']+'\n\n'
access += 'Backdoor: https://192.168.110.202'+data['private_path']+'\nЛогин: '+data['owner_login']+'\nПароль: '+data['owner_password']+'\n\nВременный HTTPS до установки сертификата владельца. Реальные сотрудники ещё не загружены.\n'
(destination/'ACCESS.md').write_text(access,encoding='utf-8')
print('Credentials generated into ignored private-runtime files; values not printed.')
