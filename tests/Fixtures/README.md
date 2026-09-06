# Test fixtures

`fcm-test-key.pem` is a throwaway RSA key used only by `PushNotificationTest` to sign the JWT
that `FcmClient` builds before its (faked) OAuth exchange. It authenticates nothing: no Google
project, no service account, no environment. Never reuse it for anything real.
