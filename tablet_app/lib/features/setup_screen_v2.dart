import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import 'package:package_info_plus/package_info_plus.dart';
import '../core/api_client.dart';
import '../core/device_store.dart';
import '../theme/tableplay_theme.dart';
import '../widgets/tableplay_brand.dart';

class SetupScreenV2 extends StatefulWidget {
  const SetupScreenV2({super.key, required this.store, required this.onPaired});
  final DeviceStore store;
  final VoidCallback onPaired;
  @override
  State<SetupScreenV2> createState() => _SetupScreenV2State();
}

class _SetupScreenV2State extends State<SetupScreenV2> {
  final name = TextEditingController(text: 'TablePlay Tablet');
  bool busy = false;
  String? error;

  @override
  void dispose() {
    name.dispose();
    super.dispose();
  }

  Future<void> scanAndPair() async {
    final raw = await Navigator.of(context).push<String>(
      MaterialPageRoute(builder: (_) => const _PairingScannerPage()),
    );
    if (raw == null || !mounted) return;
    await pairFromCode(raw);
  }

  Future<void> pasteAndPair() async {
    final controller = TextEditingController();
    final raw = await showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Paste pairing code'),
        content: TextField(
          controller: controller,
          minLines: 4,
          maxLines: 8,
          decoration: const InputDecoration(
            hintText: 'Paste the complete setup code copied from Admin',
          ),
        ),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(context),
              child: const Text('Cancel')),
          FilledButton(
              onPressed: () => Navigator.pop(context, controller.text.trim()),
              child: const Text('Connect')),
        ],
      ),
    );
    controller.dispose();
    if (raw == null || raw.isEmpty || !mounted) return;
    await pairFromCode(raw);
  }

  Future<void> pairFromCode(String raw) async {
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final payload = jsonDecode(raw) as Map<String, dynamic>;
      if (payload['type'] != 'tableplay_pairing' || payload['version'] != 2) {
        throw const FormatException(
            'This pairing code is old or invalid. Generate a new QR from TablePlay Admin.');
      }
      final base = '${payload['api_base_url']}'.replaceAll(RegExp(r'/+$'), '');
      final serverUri = Uri.tryParse(base);
      if (serverUri == null ||
          !serverUri.hasScheme ||
          !['http', 'https'].contains(serverUri.scheme) ||
          serverUri.host.isEmpty ||
          !serverUri.path.endsWith('/api/v1')) {
        throw const FormatException(
            'The QR contains an invalid server address. Open Admin using the laptop IPv4 address and generate a new QR.');
      }
      if (['127.0.0.1', 'localhost', '0.0.0.0'].contains(serverUri.host)) {
        throw const FormatException(
            'This QR uses a laptop-only address. Open Admin using the laptop IPv4 address and generate a new QR.');
      }
      final api = ApiClient(baseUrl: base);
      try {
        final branding = await api.get('/branding');
        if (branding is! Map || branding['restaurant_name'] == null) {
          throw const FormatException(
              'This address did not return a valid TablePlay restaurant server.');
        }
        final package = await PackageInfo.fromPlatform();
        final result = await api.post('/devices/pair-with-token', {
          'pairing_token': payload['pairing_token'],
          'payload_signature': payload['payload_signature'],
          'device_uuid': widget.store.uuid,
          'device_name':
              name.text.trim().isEmpty ? 'TablePlay Tablet' : name.text.trim(),
          'app_version': package.version,
        });
        await widget.store.savePairing(
          baseUrl: base,
          uuid: widget.store.uuid,
          token: result['token'],
          tableCode: '${result['table_code']}',
        );
      } finally {
        api.close();
      }
      widget.onPaired();
    } catch (exception) {
      if (mounted) {
        final raw = exception
            .toString()
            .replaceFirst('Exception: ', '')
            .replaceFirst('FormatException: ', '');
        setState(() => error = raw);
      }
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        body: Container(
          decoration: const BoxDecoration(
              gradient: LinearGradient(
                  colors: [TablePlayColors.deep, TablePlayColors.deeper],
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight)),
          child: SafeArea(
              child: Center(
                  child: SingleChildScrollView(
                      padding: const EdgeInsets.all(24),
                      child: ConstrainedBox(
                        constraints: const BoxConstraints(maxWidth: 540),
                        child: Container(
                          padding: const EdgeInsets.all(30),
                          decoration: BoxDecoration(
                              color: Colors.white,
                              borderRadius: BorderRadius.circular(24),
                              boxShadow: const [
                                BoxShadow(
                                    color: Colors.black26,
                                    blurRadius: 35,
                                    offset: Offset(0, 15))
                              ]),
                          child: Column(
                              crossAxisAlignment: CrossAxisAlignment.stretch,
                              children: [
                                const Row(
                                  children: [
                                    Expanded(child: TablePlayBrand()),
                                    _SetupBadge(),
                                  ],
                                ),
                                const SizedBox(height: 30),
                                Text('Set up this table',
                                    style: Theme.of(context)
                                        .textTheme
                                        .headlineMedium
                                        ?.copyWith(
                                            fontWeight: FontWeight.w900,
                                            letterSpacing: -1)),
                                const SizedBox(height: 7),
                                const Text(
                                    'Scan the secure one-time QR shown by an Administrator. The QR supplies the server and table automatically.',
                                    style: TextStyle(
                                        color: TablePlayColors.muted,
                                        height: 1.5)),
                                const SizedBox(height: 22),
                                const _SetupSteps(),
                                const SizedBox(height: 22),
                                TextField(
                                  controller: name,
                                  decoration: const InputDecoration(
                                    labelText: 'Device name',
                                    prefixIcon:
                                        Icon(Icons.tablet_android_outlined),
                                    hintText: 'Dining room tablet',
                                  ),
                                ),
                                const SizedBox(height: 14),
                                Container(
                                  padding: const EdgeInsets.all(16),
                                  decoration: BoxDecoration(
                                    color: const Color(0xfff1f7f5),
                                    borderRadius: BorderRadius.circular(16),
                                    border: Border.all(
                                        color: const Color(0xffd9e8e3)),
                                  ),
                                  child: Column(
                                    crossAxisAlignment:
                                        CrossAxisAlignment.stretch,
                                    children: [
                                      const Row(children: [
                                        Icon(Icons.verified_user_outlined,
                                            color: TablePlayColors.deep),
                                        SizedBox(width: 10),
                                        Expanded(
                                            child: Text(
                                                'Secure one-time pairing',
                                                style: TextStyle(
                                                    fontWeight:
                                                        FontWeight.w900))),
                                      ]),
                                      const SizedBox(height: 8),
                                      const Text(
                                          'Ask the Admin to open Setup using the laptop network address, select this table, and create a fresh QR. Each QR works once and expires automatically.',
                                          style: TextStyle(
                                              color: TablePlayColors.muted,
                                              fontSize: 11,
                                              height: 1.45)),
                                    ],
                                  ),
                                ),
                                if (error != null)
                                  Container(
                                      margin: const EdgeInsets.only(top: 15),
                                      padding: const EdgeInsets.all(13),
                                      decoration: BoxDecoration(
                                          color: const Color(0xffffebe7),
                                          borderRadius:
                                              BorderRadius.circular(12)),
                                      child: Row(
                                          crossAxisAlignment:
                                              CrossAxisAlignment.start,
                                          children: [
                                            const Icon(Icons.error_outline,
                                                color: Color(0xffc34836),
                                                size: 20),
                                            const SizedBox(width: 9),
                                            Expanded(
                                                child: Text(error!,
                                                    style: const TextStyle(
                                                        color:
                                                            Color(0xff9d3425),
                                                        fontSize: 12)))
                                          ])),
                                const SizedBox(height: 20),
                                FilledButton.icon(
                                    onPressed: busy ? null : scanAndPair,
                                    icon: busy
                                        ? const SizedBox.square(
                                            dimension: 18,
                                            child: CircularProgressIndicator(
                                                strokeWidth: 2,
                                                color: Colors.white))
                                        : const Icon(
                                            Icons.qr_code_scanner_rounded),
                                    label: Text(busy
                                        ? 'Verifying server and table…'
                                        : 'Scan and connect this table'),
                                    style: FilledButton.styleFrom(
                                      minimumSize: const Size.fromHeight(54),
                                      backgroundColor: TablePlayColors.accent,
                                    )),
                                TextButton.icon(
                                  onPressed: busy ? null : pasteAndPair,
                                  icon: const Icon(Icons.content_paste_rounded),
                                  label: const Text(
                                      'Camera unavailable? Paste setup code'),
                                ),
                                const SizedBox(height: 13),
                                const Row(
                                    mainAxisAlignment: MainAxisAlignment.center,
                                    children: [
                                      Icon(Icons.wifi_rounded,
                                          size: 15,
                                          color: TablePlayColors.success),
                                      SizedBox(width: 6),
                                      Flexible(
                                          child: Text(
                                              'Tablet and server must use the same private Wi-Fi',
                                              style: TextStyle(
                                                  color: TablePlayColors.muted,
                                                  fontSize: 11),
                                              textAlign: TextAlign.center))
                                    ]),
                                const SizedBox(height: 8),
                                ExpansionTile(
                                  tilePadding: EdgeInsets.zero,
                                  childrenPadding:
                                      const EdgeInsets.only(bottom: 4),
                                  leading: const Icon(
                                      Icons.help_outline_rounded,
                                      size: 20),
                                  title: const Text('Connection checklist',
                                      style: TextStyle(
                                          fontSize: 12,
                                          fontWeight: FontWeight.w800)),
                                  children: const [
                                    _ChecklistLine(
                                        'Use the laptop IP—not 127.0.0.1 or 0.0.0.0.'),
                                    _ChecklistLine(
                                        'Allow connected devices to communicate on the router or hotspot.'),
                                    _ChecklistLine(
                                        'Generate a new QR after the laptop network changes.'),
                                  ],
                                ),
                              ]),
                        ),
                      )))),
        ),
      );
}

class _SetupBadge extends StatelessWidget {
  const _SetupBadge();

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 6),
        decoration: BoxDecoration(
          color: TablePlayColors.success.withValues(alpha: .09),
          borderRadius: BorderRadius.circular(9),
        ),
        child: const Text(
          'ABOUT 1 MIN',
          style: TextStyle(
            color: TablePlayColors.success,
            fontSize: 8,
            letterSpacing: .7,
            fontWeight: FontWeight.w900,
          ),
        ),
      );
}

class _SetupSteps extends StatelessWidget {
  const _SetupSteps();

  @override
  Widget build(BuildContext context) => const Row(
        children: [
          Expanded(
              child:
                  _SetupStep(number: '1', label: 'Name device', active: true)),
          _StepConnector(),
          Expanded(child: _SetupStep(number: '2', label: 'Scan QR')),
          _StepConnector(),
          Expanded(child: _SetupStep(number: '3', label: 'Ready')),
        ],
      );
}

class _SetupStep extends StatelessWidget {
  const _SetupStep(
      {required this.number, required this.label, this.active = false});

  final String number;
  final String label;
  final bool active;

  @override
  Widget build(BuildContext context) => Column(
        children: [
          Container(
            width: 28,
            height: 28,
            alignment: Alignment.center,
            decoration: BoxDecoration(
              color: active
                  ? TablePlayColors.accent
                  : TablePlayColors.surfaceMuted,
              shape: BoxShape.circle,
            ),
            child: Text(
              number,
              style: TextStyle(
                color: active ? Colors.white : TablePlayColors.muted,
                fontSize: 11,
                fontWeight: FontWeight.w900,
              ),
            ),
          ),
          const SizedBox(height: 6),
          Text(label,
              style: const TextStyle(fontSize: 9, fontWeight: FontWeight.w800)),
        ],
      );
}

class _StepConnector extends StatelessWidget {
  const _StepConnector();

  @override
  Widget build(BuildContext context) => const Expanded(
        child: Padding(
          padding: EdgeInsets.only(bottom: 20),
          child: Divider(),
        ),
      );
}

class _ChecklistLine extends StatelessWidget {
  const _ChecklistLine(this.text);

  final String text;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(left: 8, right: 6, bottom: 8),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Icon(Icons.check_circle_outline_rounded,
                size: 16, color: TablePlayColors.success),
            const SizedBox(width: 8),
            Expanded(
                child: Text(text,
                    style: const TextStyle(
                        color: TablePlayColors.muted,
                        fontSize: 10.5,
                        height: 1.35))),
          ],
        ),
      );
}

class _PairingScannerPage extends StatefulWidget {
  const _PairingScannerPage();
  @override
  State<_PairingScannerPage> createState() => _PairingScannerPageState();
}

class _PairingScannerPageState extends State<_PairingScannerPage> {
  bool detected = false;
  @override
  Widget build(BuildContext context) => Scaffold(
        backgroundColor: Colors.black,
        appBar: AppBar(
            title: const Text('Scan TablePlay QR'),
            backgroundColor: Colors.black,
            foregroundColor: Colors.white),
        body: Stack(children: [
          MobileScanner(onDetect: (capture) {
            if (detected) return;
            final value = capture.barcodes.firstOrNull?.rawValue;
            if (value == null) return;
            detected = true;
            Navigator.pop(context, value);
          }),
          Center(
              child: Container(
                  width: 260,
                  height: 260,
                  decoration: BoxDecoration(
                      border:
                          Border.all(color: TablePlayColors.accent, width: 3),
                      borderRadius: BorderRadius.circular(24)))),
          const Positioned(
              left: 24,
              right: 24,
              bottom: 42,
              child: Text(
                  'Point the camera at the pairing QR shown in Admin. It can be used only once.',
                  textAlign: TextAlign.center,
                  style: TextStyle(
                      color: Colors.white, fontWeight: FontWeight.w700))),
        ]),
      );
}
