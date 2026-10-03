import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import '../core/api_client.dart';
import '../core/session_store.dart';
import '../theme/tableplay_theme.dart';
import '../widgets/common.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key, required this.store, required this.onSignedIn});
  final SessionStore store;
  final VoidCallback onSignedIn;
  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final username = TextEditingController();
  final credential = TextEditingController();
  late final TextEditingController server;
  bool usePin = false, busy = false, showServer = false, showCredential = false;
  String? error;
  String? connectionMessage;

  @override
  void initState() {
    super.initState();
    server = TextEditingController(text: widget.store.baseUrl);
    showServer = widget.store.baseUrl.trim().isEmpty;
  }

  Future<void> login() async {
    if (busy || !(_formKey.currentState?.validate() ?? false)) return;
    setState(() {
      busy = true;
      error = null;
    });
    try {
      final baseUrl = _validatedBaseUrl(server.text);
      final serverUri = Uri.tryParse(baseUrl);
      if (serverUri == null ||
          !serverUri.hasScheme ||
          !['http', 'https'].contains(serverUri.scheme) ||
          serverUri.host.isEmpty ||
          !serverUri.path.endsWith('/api/v1')) {
        throw const FormatException(
          'Enter a valid API address ending in /api/v1, for example http://192.168.1.10:8000/api/v1.',
        );
      }
      if (defaultTargetPlatform != TargetPlatform.windows &&
          ['127.0.0.1', 'localhost', '0.0.0.0'].contains(serverUri.host)) {
        throw const FormatException(
          'On a phone, use the laptop IPv4 address—not localhost or 0.0.0.0.',
        );
      }
      final api = ApiClient(baseUrl: baseUrl);
      late final dynamic data;
      try {
        data = await api.post('/auth/login', {
          'username': username.text.trim(),
          usePin ? 'pin' : 'password': credential.text,
          'device_name': defaultTargetPlatform == TargetPlatform.windows
              ? 'TablePlay Staff Windows'
              : 'TablePlay Staff Android',
        });
      } finally {
        api.close();
      }
      final role = data['user']['role']['name'].toString();
      if (!['admin', 'counter', 'kitchen', 'waiter'].contains(role)) {
        throw Exception('This role is not supported by the staff app.');
      }
      await widget.store.save(
        baseUrl: baseUrl,
        token: data['token'],
        role: role,
        name: data['user']['name'],
        userId: data['user']['id'],
      );
      await widget.store.saveServer(baseUrl);
      credential.clear();
      widget.onSignedIn();
    } catch (exception) {
      if (mounted) {
        final message = exception.toString().replaceFirst(
          RegExp(r'^(?:Exception|FormatException):\s*'),
          '',
        );
        setState(() => error = message);
      }
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  String _validatedBaseUrl(String value) {
    final baseUrl = value.trim().replaceAll(RegExp(r'/+$'), '');
    final uri = Uri.tryParse(baseUrl);
    if (uri == null ||
        !uri.hasScheme ||
        !['http', 'https'].contains(uri.scheme) ||
        uri.host.isEmpty ||
        !uri.path.endsWith('/api/v1')) {
      throw const FormatException(
        'Enter a valid API address ending in /api/v1, for example http://192.168.1.10:8000/api/v1.',
      );
    }
    if (defaultTargetPlatform != TargetPlatform.windows &&
        ['127.0.0.1', 'localhost', '0.0.0.0'].contains(uri.host)) {
      throw const FormatException(
        'On a phone, use the laptop IPv4 address—not localhost or 0.0.0.0.',
      );
    }
    return baseUrl;
  }

  Future<void> testConnection({String? serverName}) async {
    if (busy) return;
    setState(() {
      busy = true;
      error = null;
      connectionMessage = null;
    });
    try {
      final baseUrl = _validatedBaseUrl(server.text);
      dynamic branding;
      Object? lastError;
      for (var attempt = 0; attempt < 2; attempt++) {
        final api = ApiClient(baseUrl: baseUrl);
        try {
          branding = await api.get('/branding');
          break;
        } catch (exception) {
          lastError = exception;
          if (attempt == 0) {
            await Future<void>.delayed(const Duration(milliseconds: 450));
          }
        } finally {
          api.close();
        }
      }
      if (branding is! Map || branding['restaurant_name'] == null) {
        throw lastError ??
            const FormatException('This is not a TablePlay restaurant server.');
      }
      final name = serverName ?? branding['restaurant_name'].toString();
      await widget.store.saveServer(baseUrl, serverName: name);
      if (mounted) {
        setState(() => connectionMessage =
            'Connected to $name. You can now sign in.');
      }
    } catch (exception) {
      if (mounted) {
        setState(() => error = exception
            .toString()
            .replaceFirst(RegExp(r'^(?:Exception|FormatException):\s*'), ''));
      }
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> scanServer() async {
    final raw = await Navigator.of(context).push<String>(
      MaterialPageRoute(builder: (_) => const _StaffServerScannerPage()),
    );
    if (raw == null || !mounted) return;
    try {
      final payload = jsonDecode(raw) as Map<String, dynamic>;
      if (payload['type'] != 'tableplay_staff_connection' ||
          payload['version'] != 1 ||
          '${payload['server_id']}'.trim().isEmpty) {
        throw const FormatException(
            'This is not a current TablePlay Staff connection QR.');
      }
      server.text = _validatedBaseUrl('${payload['api_base_url']}');
      setState(() => showServer = true);
      await testConnection(serverName: '${payload['server_name']}');
    } catch (exception) {
      if (mounted) {
        setState(() => error = exception
            .toString()
            .replaceFirst(RegExp(r'^(?:Exception|FormatException):\s*'), ''));
      }
    }
  }

  Future<void> forgetServer() async {
    await widget.store.forgetServer();
    server.text = defaultTargetPlatform == TargetPlatform.windows
        ? 'http://127.0.0.1:8000/api/v1'
        : '';
    if (mounted) {
      setState(() {
        showServer = true;
        connectionMessage = 'Saved server removed.';
        error = null;
      });
    }
  }

  @override
  void dispose() {
    username.dispose();
    credential.dispose();
    server.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    body: DecoratedBox(
      decoration: const BoxDecoration(
        gradient: LinearGradient(
          colors: [TablePlayColors.deeper, TablePlayColors.deep],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
      ),
      child: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(20),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 500),
              child: Card(
                child: Padding(
                  padding: const EdgeInsets.all(28),
                  child: AutofillGroup(
                    child: Form(
                      key: _formKey,
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          const TablePlayBrand(),
                          const SizedBox(height: 28),
                          Container(
                            padding: const EdgeInsets.symmetric(
                              horizontal: 10,
                              vertical: 7,
                            ),
                            decoration: BoxDecoration(
                              color: TablePlayColors.success.withValues(
                                alpha: .08,
                              ),
                              borderRadius: BorderRadius.circular(10),
                              border: Border.all(
                                color: TablePlayColors.success.withValues(
                                  alpha: .16,
                                ),
                              ),
                            ),
                            child: const Row(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                Icon(
                                  Icons.shield_outlined,
                                  size: 15,
                                  color: TablePlayColors.success,
                                ),
                                SizedBox(width: 6),
                                Text(
                                  'SECURE LOCAL WORKSPACE',
                                  style: TextStyle(
                                    color: TablePlayColors.success,
                                    fontSize: 9,
                                    letterSpacing: .8,
                                    fontWeight: FontWeight.w900,
                                  ),
                                ),
                              ],
                            ),
                          ),
                          const SizedBox(height: 16),
                          const Text(
                            'Staff sign in',
                            style: TextStyle(
                              fontSize: 28,
                              fontWeight: FontWeight.w900,
                            ),
                          ),
                          const SizedBox(height: 5),
                          const Text(
                            'One secure app for Admin, Counter, Kitchen, and Waiter teams.',
                            style: TextStyle(color: TablePlayColors.muted),
                          ),
                          if (widget.store.baseUrl.trim().isNotEmpty) ...[
                            const SizedBox(height: 12),
                            Container(
                              padding: const EdgeInsets.all(11),
                              decoration: BoxDecoration(
                                color: TablePlayColors.surfaceMuted,
                                borderRadius: BorderRadius.circular(11),
                              ),
                              child: Text(
                                '${widget.store.serverName ?? 'Restaurant server'} · ${widget.store.baseUrl}',
                                style: const TextStyle(
                                  color: TablePlayColors.muted,
                                  fontSize: 10.5,
                                  fontWeight: FontWeight.w700,
                                ),
                              ),
                            ),
                          ],
                          if (connectionMessage != null) ...[
                            const SizedBox(height: 10),
                            Text(
                              connectionMessage!,
                              style: const TextStyle(
                                color: TablePlayColors.success,
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                          ],
                          if (defaultTargetPlatform != TargetPlatform.windows)
                            Padding(
                              padding: const EdgeInsets.only(top: 10),
                              child: OutlinedButton.icon(
                                key: const Key('staff-server-scan'),
                                onPressed: busy ? null : scanServer,
                                icon: const Icon(Icons.qr_code_scanner_rounded),
                                label: const Text('Scan restaurant server QR'),
                              ),
                            ),
                          const SizedBox(height: 15),
                          const Wrap(
                            spacing: 7,
                            runSpacing: 7,
                            children: [
                              _RoleChip(
                                Icons.admin_panel_settings_outlined,
                                'Admin',
                              ),
                              _RoleChip(
                                Icons.point_of_sale_outlined,
                                'Counter',
                              ),
                              _RoleChip(Icons.soup_kitchen_outlined, 'Kitchen'),
                              _RoleChip(Icons.room_service_outlined, 'Waiter'),
                            ],
                          ),
                          if (error != null) ...[
                            const SizedBox(height: 16),
                            Container(
                              padding: const EdgeInsets.all(12),
                              decoration: BoxDecoration(
                                color: const Color(0xffffebe7),
                                borderRadius: BorderRadius.circular(12),
                              ),
                              child: Row(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  const Icon(
                                    Icons.error_outline_rounded,
                                    size: 19,
                                    color: TablePlayColors.danger,
                                  ),
                                  const SizedBox(width: 9),
                                  Expanded(
                                    child: Text(
                                      error!,
                                      style: const TextStyle(
                                        color: TablePlayColors.danger,
                                        height: 1.35,
                                      ),
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          ],
                          const SizedBox(height: 22),
                          TextFormField(
                            key: const Key('staff-username-field'),
                            controller: username,
                            autofillHints: const [AutofillHints.username],
                            textInputAction: TextInputAction.next,
                            inputFormatters: [
                              LengthLimitingTextInputFormatter(100),
                            ],
                            validator: (value) => (value ?? '').trim().isEmpty
                                ? 'Enter your username.'
                                : null,
                            decoration: const InputDecoration(
                              labelText: 'Username',
                              prefixIcon: Icon(Icons.person_outline_rounded),
                            ),
                          ),
                          const SizedBox(height: 13),
                          TextFormField(
                            key: const Key('staff-credential-field'),
                            controller: credential,
                            obscureText: !showCredential,
                            autofillHints: usePin
                                ? null
                                : const [AutofillHints.password],
                            keyboardType: usePin
                                ? TextInputType.number
                                : TextInputType.text,
                            inputFormatters: [
                              if (usePin)
                                FilteringTextInputFormatter.digitsOnly,
                              LengthLimitingTextInputFormatter(
                                usePin ? 12 : 255,
                              ),
                            ],
                            validator: (value) {
                              final text = value ?? '';
                              if (text.isEmpty) {
                                return usePin
                                    ? 'Enter your staff PIN.'
                                    : 'Enter your password.';
                              }
                              if (usePin &&
                                  (text.length < 4 || text.length > 12)) {
                                return 'PIN must contain 4 to 12 digits.';
                              }
                              return null;
                            },
                            onFieldSubmitted: (_) => login(),
                            decoration: InputDecoration(
                              labelText: usePin ? 'PIN' : 'Password',
                              prefixIcon: Icon(
                                usePin
                                    ? Icons.pin_outlined
                                    : Icons.lock_outline_rounded,
                              ),
                              suffixIcon: IconButton(
                                key: const Key('staff-credential-visibility'),
                                tooltip: showCredential
                                    ? 'Hide credential'
                                    : 'Show credential',
                                onPressed: () => setState(
                                  () => showCredential = !showCredential,
                                ),
                                icon: Icon(
                                  showCredential
                                      ? Icons.visibility_off_outlined
                                      : Icons.visibility_outlined,
                                ),
                              ),
                            ),
                          ),
                          SwitchListTile(
                            contentPadding: EdgeInsets.zero,
                            value: usePin,
                            onChanged: (value) => setState(() {
                              usePin = value;
                              showCredential = false;
                              credential.clear();
                            }),
                            title: const Text(
                              'Sign in with staff PIN',
                              style: TextStyle(
                                fontSize: 12,
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                          ),
                          TextButton.icon(
                            onPressed: () =>
                                setState(() => showServer = !showServer),
                            icon: const Icon(Icons.lan_outlined, size: 18),
                            label: Text(
                              showServer
                                  ? 'Hide server settings'
                                  : 'Local server settings',
                            ),
                          ),
                          if (showServer)
                            Column(
                              children: [
                                TextFormField(
                                  key: const Key('staff-server-field'),
                                  controller: server,
                                  keyboardType: TextInputType.url,
                                  inputFormatters: [
                                    LengthLimitingTextInputFormatter(500),
                                  ],
                                  validator: (value) =>
                                      (value ?? '').trim().isEmpty
                                          ? 'Enter the local server API address.'
                                          : null,
                                  decoration: const InputDecoration(
                                    labelText: 'API address',
                                    hintText:
                                        'http://192.168.1.10:8000/api/v1',
                                    helperText:
                                        'Use localhost on this PC or the server IP on another device.',
                                    prefixIcon: Icon(Icons.dns_outlined),
                                  ),
                                ),
                                const SizedBox(height: 8),
                                Row(
                                  children: [
                                    Expanded(
                                      child: OutlinedButton.icon(
                                        onPressed:
                                            busy ? null : testConnection,
                                        icon: const Icon(
                                            Icons.wifi_tethering_rounded),
                                        label: const Text('Test connection'),
                                      ),
                                    ),
                                    const SizedBox(width: 8),
                                    TextButton(
                                      onPressed: busy ? null : forgetServer,
                                      child: const Text('Forget server'),
                                    ),
                                  ],
                                ),
                              ],
                            ),
                          const SizedBox(height: 14),
                          FilledButton.icon(
                            onPressed: busy ? null : login,
                            icon: busy
                                ? const SizedBox.square(
                                    dimension: 18,
                                    child: CircularProgressIndicator(
                                      strokeWidth: 2,
                                      color: Colors.white,
                                    ),
                                  )
                                : const Icon(Icons.arrow_forward_rounded),
                            label: Text(
                              busy ? 'Signing in…' : 'Open my dashboard',
                            ),
                            style: FilledButton.styleFrom(
                              backgroundColor: TablePlayColors.accent,
                            ),
                          ),
                          const SizedBox(height: 13),
                          const Row(
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: [
                              Icon(
                                Icons.lan_rounded,
                                size: 14,
                                color: TablePlayColors.success,
                              ),
                              SizedBox(width: 6),
                              Expanded(
                                child: Text(
                                  'Works on the restaurant network without internet',
                                  textAlign: TextAlign.center,
                                  style: TextStyle(
                                    fontSize: 10,
                                    color: TablePlayColors.muted,
                                  ),
                                ),
                              ),
                            ],
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    ),
  );
}

class _StaffServerScannerPage extends StatefulWidget {
  const _StaffServerScannerPage();

  @override
  State<_StaffServerScannerPage> createState() =>
      _StaffServerScannerPageState();
}

class _StaffServerScannerPageState extends State<_StaffServerScannerPage> {
  bool detected = false;

  @override
  Widget build(BuildContext context) => Scaffold(
        backgroundColor: Colors.black,
        appBar: AppBar(
          title: const Text('Scan Staff connection QR'),
          backgroundColor: Colors.black,
          foregroundColor: Colors.white,
        ),
        body: Stack(
          children: [
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
                  border: Border.all(color: TablePlayColors.accent, width: 3),
                  borderRadius: BorderRadius.circular(24),
                ),
              ),
            ),
            const Positioned(
              left: 24,
              right: 24,
              bottom: 42,
              child: Text(
                'Scan the Staff Connection QR from Admin Setup. This QR configures the server only and never assigns a table.',
                textAlign: TextAlign.center,
                style: TextStyle(
                  color: Colors.white,
                  fontWeight: FontWeight.w700,
                ),
              ),
            ),
          ],
        ),
      );
}

class _RoleChip extends StatelessWidget {
  const _RoleChip(this.icon, this.label);

  final IconData icon;
  final String label;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7),
    decoration: BoxDecoration(
      color: TablePlayColors.surfaceMuted,
      borderRadius: BorderRadius.circular(10),
      border: Border.all(color: TablePlayColors.border),
    ),
    child: Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(icon, size: 15, color: TablePlayColors.deep),
        const SizedBox(width: 6),
        Text(
          label,
          style: const TextStyle(fontSize: 10, fontWeight: FontWeight.w800),
        ),
      ],
    ),
  );
}
