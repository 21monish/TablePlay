import 'package:flutter/material.dart';
import 'package:tableplay_update/tableplay_update.dart';
import '../core/device_store.dart';
import '../theme/tableplay_theme.dart';
import 'home_screen_v2.dart';
import 'setup_screen_v2.dart';

class TablePlayRoot extends StatefulWidget {
  const TablePlayRoot({super.key, required this.store});
  final DeviceStore store;
  @override
  State<TablePlayRoot> createState() => _TablePlayRootState();
}

class _TablePlayRootState extends State<TablePlayRoot> {
  @override
  Widget build(BuildContext context) => MaterialApp(
      debugShowCheckedModeBanner: false,
      title: 'TablePlay',
      theme: tablePlayTheme(),
      home: LocalUpdateGate(
          app: 'customer',
          baseUrl: widget.store.baseUrl,
          installationUuid: widget.store.uuid,
          deviceName: widget.store.tableCode == null
              ? 'Unpaired customer tablet'
              : '${widget.store.tableCode} customer tablet',
          headers: {
            'X-Device-UUID': widget.store.uuid,
            if (widget.store.token != null)
              'Authorization': 'Bearer ${widget.store.token}',
          },
          accentColor: TablePlayColors.accent,
          child: widget.store.isPaired
              ? HomeScreenV2(
                  store: widget.store, onReset: () => setState(() {}))
              : SetupScreenV2(
                  store: widget.store, onPaired: () => setState(() {}))));
}
