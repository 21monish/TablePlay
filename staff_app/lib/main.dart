import 'dart:io';

import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:tableplay_update/tableplay_update.dart';

import 'core/session_store.dart';
import 'features/login_screen.dart';
import 'features/staff_home.dart';
import 'theme/tableplay_theme.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(
    TablePlayStaffApp(
      store: SessionStore(await SharedPreferences.getInstance()),
    ),
  );
}

class TablePlayStaffApp extends StatefulWidget {
  const TablePlayStaffApp({super.key, required this.store});
  final SessionStore store;
  @override
  State<TablePlayStaffApp> createState() => _TablePlayStaffAppState();
}

class _TablePlayStaffAppState extends State<TablePlayStaffApp> {
  @override
  Widget build(BuildContext context) => MaterialApp(
    debugShowCheckedModeBanner: false,
    title: 'TablePlay Staff',
    theme: tablePlayTheme(),
    home: LocalUpdateGate(
      app: 'staff',
      baseUrl: widget.store.baseUrl,
      installationUuid: widget.store.installationUuid,
      deviceName:
          '${widget.store.name ?? 'TablePlay Staff'} · ${Platform.isWindows ? 'Windows' : 'Android'}',
      headers: {
        if (widget.store.token != null)
          'Authorization': 'Bearer ${widget.store.token}',
      },
      accentColor: TablePlayColors.accent,
      child: widget.store.signedIn
          ? StaffHome(store: widget.store, onSignedOut: () => setState(() {}))
          : LoginScreen(store: widget.store, onSignedIn: () => setState(() {})),
    ),
  );
}
