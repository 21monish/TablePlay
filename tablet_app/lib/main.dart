import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'core/device_store.dart';
import 'features/tablet_app.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(
      TablePlayRoot(store: DeviceStore(await SharedPreferences.getInstance())));
}
