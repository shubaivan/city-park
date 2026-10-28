Files the server reads at run time that are not code.

- `fonts/DejaVuSans*.ttf` — the power-schedule picture (`PowerScheduleImage`). Shipped
  here rather than read from `/usr/share/fonts`, because what is installed on prod is not
  ours to assume and a missing font draws nothing at all. Free licence, in `fonts/`.
- `certs/sectigo-public-server-authentication-ca-dv-r36.pem` — the intermediate
  certificate `cabinet.cherkasyoblenergo.com` does not send. Its server hands over the
  leaf alone, so every client that does not fetch intermediates itself (curl, PHP)
  refuses the connection. `PowerQueueResolver` verifies against the system bundle plus
  this file. Public, expires 2036.
