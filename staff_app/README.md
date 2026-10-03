# TablePlay Staff

Flutter client for Admin, Counter, Kitchen, and Waiter staff on Android and Windows. It uses role-based Laravel Sanctum login, responsive drawer/sidebar navigation, local-network operations, and the TablePlay local update client.

```powershell
flutter pub get
flutter analyze
flutter test
flutter run -d windows
```

Android release builds require the protected signing setup described in `../docs/LOCAL_APP_UPDATES.md`. Do not distribute or publish debug-signed APKs.
