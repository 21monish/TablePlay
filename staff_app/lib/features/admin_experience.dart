import 'dart:async';
import 'dart:convert';
import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:qr_flutter/qr_flutter.dart';

import '../theme/tableplay_theme.dart';
import '../widgets/common.dart';

String licenseDate(dynamic value) {
  final date = DateTime.tryParse('$value')?.toLocal();
  if (date == null) return 'Not set';
  String two(int number) => number.toString().padLeft(2, '0');
  return '${two(date.day)}/${two(date.month)}/${date.year} '
      '${two(date.hour)}:${two(date.minute)}';
}

bool menuMatches(Map item, String query, String availability) {
  final text =
      '${item['name'] ?? ''} ${item['description'] ?? ''} '
              '${item['short_description'] ?? ''} ${item['ingredients'] ?? ''}'
          .toLowerCase();
  return text.contains(query.trim().toLowerCase()) &&
      (availability == 'all' ||
          (item['is_available'] == true) == (availability == 'available'));
}

class SetupGuidePanel extends StatelessWidget {
  const SetupGuidePanel({
    super.key,
    required this.data,
    required this.onNavigate,
    required this.onStaffQr,
  });
  final Map data;
  final void Function(String key) onNavigate;
  final VoidCallback onStaffQr;

  @override
  Widget build(BuildContext context) {
    final connection = data['connection'] as Map?;
    final steps = data['steps'] as List? ?? [];
    final releases = data['releases'] as List? ?? [];
    final descriptions = {
      'identity': 'Restaurant name, contact details, currency and tax.',
      'health': 'Check the database, storage and local server.',
      'tables': 'Create the dining floor and generate a Customer app QR.',
      'staff': 'Create Counter, Kitchen and Waiter accounts.',
      'apps': 'Install the current Staff and Customer applications.',
      'pairing': 'Scan a single-use QR from Tables on the customer tablet.',
      'test':
          'Place an order, confirm it, prepare it, serve it and settle the bill.',
    };
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const SectionTitle(
          'Restaurant setup',
          subtitle: 'The same server-checked checklist as the web application',
        ),
        Card(
          child: Padding(
            padding: const EdgeInsets.all(20),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  '${data['completed'] ?? 0} of ${data['total'] ?? 0} checks complete',
                  style: Theme.of(context).textTheme.titleLarge,
                ),
                const SizedBox(height: 12),
                LinearProgressIndicator(
                  value: ((data['percent'] as num? ?? 0) / 100)
                      .clamp(0, 1)
                      .toDouble(),
                ),
                const SizedBox(height: 12),
                const Text(
                  'Setup checks do not replace a real tablet-to-kitchen order test.',
                ),
              ],
            ),
          ),
        ),
        ...steps.map((value) {
          final step = value as Map;
          final complete = step['complete'] == true;
          return Card(
            child: ListTile(
              leading: Icon(
                complete
                    ? Icons.check_circle_rounded
                    : Icons.radio_button_unchecked_rounded,
                color: complete
                    ? TablePlayColors.success
                    : TablePlayColors.warning,
              ),
              title: Text('${step['label']}'),
              subtitle: Text(
                descriptions[step['key']] ?? 'Review this setup step.',
              ),
              trailing: IconButton(
                tooltip: 'Open ${step['label']}',
                onPressed: () => onNavigate('${step['key']}'),
                icon: const Icon(Icons.arrow_forward_rounded),
              ),
            ),
          );
        }),
        const SectionTitle(
          'Connect devices',
          subtitle:
              'Keep phones, tablets and the server on the same restaurant Wi-Fi',
        ),
        if (connection == null)
          Card(
            child: ListTile(
              leading: const Icon(Icons.wifi_off),
              title: const Text('A restaurant network address is needed'),
              subtitle: Text(
                '${data['network_warning'] ?? 'Run Network Doctor in TablePlay Server Manager.'}',
              ),
            ),
          )
        else
          Card(
            child: Padding(
              padding: const EdgeInsets.all(20),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const Text(
                    'Tablet and phone API address',
                    style: TextStyle(fontWeight: FontWeight.w800),
                  ),
                  const SizedBox(height: 8),
                  SelectableText('${connection['api_base_url']}'),
                  const SizedBox(height: 12),
                  const Text(
                    '127.0.0.1 works only on the server laptop. On another device use the address above. A Staff QR connects the server; staff must still sign in.',
                  ),
                  const SizedBox(height: 14),
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: [
                      FilledButton.icon(
                        onPressed: onStaffQr,
                        icon: const Icon(Icons.qr_code_rounded),
                        label: const Text('Staff connection QR'),
                      ),
                      OutlinedButton.icon(
                        onPressed: () => onNavigate('pairing'),
                        icon: const Icon(Icons.tablet_android_rounded),
                        label: const Text('Pair Customer tablet'),
                      ),
                      OutlinedButton.icon(
                        onPressed: () => Clipboard.setData(
                          ClipboardData(text: '${connection['api_base_url']}'),
                        ),
                        icon: const Icon(Icons.copy_rounded),
                        label: const Text('Copy address'),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ),
        const SectionTitle(
          'Applications',
          subtitle: 'Versions published by this restaurant server',
        ),
        if (releases.isEmpty)
          const Card(
            child: ListTile(
              title: Text('No application downloads published yet'),
            ),
          ),
        ...releases.map((value) {
          final release = value as Map;
          final target = '${release['app']}-${release['platform']}';
          final address = connection == null
              ? null
              : '${connection['api_base_url']}/app-updates/download/$target';
          return Card(
            child: ListTile(
              leading: Icon(
                release['app'] == 'staff'
                    ? Icons.badge_outlined
                    : Icons.tablet_android_rounded,
              ),
              title: Text('${release['app']} - ${release['platform']}'),
              subtitle: Text('Version ${release['version']}'),
              trailing: IconButton(
                tooltip: 'Copy download link',
                onPressed: address == null
                    ? null
                    : () async {
                        await Clipboard.setData(ClipboardData(text: address));
                        if (context.mounted) {
                          ScaffoldMessenger.of(context).showSnackBar(
                            const SnackBar(
                              content: Text(
                                'Download link copied. Open it in the device browser.',
                              ),
                            ),
                          );
                        }
                      },
                icon: const Icon(Icons.download_rounded),
              ),
            ),
          );
        }),
      ],
    );
  }
}

class LicensePanel extends StatefulWidget {
  const LicensePanel({
    super.key,
    required this.data,
    required this.onActivate,
    required this.onSync,
    required this.onExport,
    required this.onImport,
  });
  final Map data;
  final Future<void> Function(String url, String key) onActivate;
  final Future<void> Function() onSync, onExport, onImport;
  @override
  State<LicensePanel> createState() => _LicensePanelState();
}

class _LicensePanelState extends State<LicensePanel> {
  final _formKey = GlobalKey<FormState>();
  late final TextEditingController _cloud;
  final _key = TextEditingController();
  bool _busy = false, _showKey = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _cloud = TextEditingController(
      text: '${widget.data['installation']?['cloud_url'] ?? ''}',
    );
  }

  @override
  void dispose() {
    _cloud.dispose();
    _key.dispose();
    super.dispose();
  }

  Future<void> _run(Future<void> Function() action) async {
    if (_busy) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await action();
    } catch (error) {
      if (mounted) setState(() => _error = error.toString());
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final state = widget.data['entitlements'] as Map? ?? {};
    final installation = widget.data['installation'] as Map? ?? {};
    final warnings = state['warnings'] as List? ?? [];
    final features = state['features'] as Map? ?? {};
    final logs = widget.data['sync_logs'] as List? ?? [];
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const SectionTitle(
          'Verified restaurant licence',
          subtitle:
              'Subscription dates and limits are calculated by the server',
        ),
        Card(
          child: Padding(
            padding: const EdgeInsets.all(20),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Wrap(
                  spacing: 12,
                  runSpacing: 12,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: [
                    Text(
                      '${state['plan']?['name'] ?? 'Not licensed'}',
                      style: Theme.of(context).textTheme.headlineSmall
                          ?.copyWith(fontWeight: FontWeight.w900),
                    ),
                    StatusPill('${state['status'] ?? 'missing'}'),
                    Chip(
                      label: Text(
                        state['plan'] == null
                            ? 'No active subscription'
                            : state['days_remaining'] == null
                            ? 'No subscription expiry'
                            : '${state['days_remaining']} days remaining',
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 14),
                _line(
                  'Signature verification',
                  state['signature_valid'] == true
                      ? 'Verified'
                      : 'Not verified',
                ),
                _line(
                  'Subscription expires',
                  state['subscription_expires_at'] == null
                      ? 'No expiry set'
                      : licenseDate(state['subscription_expires_at']),
                ),
                _line(
                  'Offline verification due',
                  licenseDate(state['offline_verification_due_at']),
                ),
                _line('Grace period ends', licenseDate(state['grace_ends_at'])),
                _line(
                  'Customer tablets',
                  state['licensed'] != true
                      ? 'Pairing unavailable'
                      : '${state['usage']?['paired_tables'] ?? 0} / ${state['limits']?['paired_tables'] ?? 'Unlimited'}',
                ),
                _line(
                  'Last cloud sync',
                  licenseDate(installation['last_sync_at']),
                ),
                _line(
                  'Installation ID',
                  '${installation['installation_uuid'] ?? 'Not available'}',
                ),
                const SizedBox(height: 12),
                const Text(
                  'Subscription expiry and offline verification are different. Refreshing an offline licence does not renew a subscription. Restaurant orders and bills stay on the local server.',
                ),
                const SizedBox(height: 14),
                Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    FilledButton.icon(
                      onPressed:
                          _busy || installation['online_activated'] != true
                          ? null
                          : () => _run(widget.onSync),
                      icon: const Icon(Icons.sync_rounded),
                      label: const Text('Synchronize now'),
                    ),
                    OutlinedButton.icon(
                      onPressed: _busy ? null : () => _run(widget.onExport),
                      icon: const Icon(Icons.download_rounded),
                      label: const Text('Export offline request'),
                    ),
                    OutlinedButton.icon(
                      onPressed: _busy ? null : () => _run(widget.onImport),
                      icon: const Icon(Icons.upload_file_rounded),
                      label: const Text('Import signed licence'),
                    ),
                  ],
                ),
                if (installation['online_activated'] != true)
                  const Padding(
                    padding: EdgeInsets.only(top: 10),
                    child: Text(
                      'Online synchronization becomes available after online activation.',
                    ),
                  ),
              ],
            ),
          ),
        ),
        ...warnings.map(
          (warning) => Card(
            color: TablePlayColors.gold.withValues(alpha: .12),
            child: ListTile(
              leading: const Icon(Icons.warning_amber_rounded),
              title: Text('${warning['message']}'),
            ),
          ),
        ),
        if (_error != null)
          Card(
            child: ListTile(
              leading: const Icon(
                Icons.error_outline,
                color: TablePlayColors.danger,
              ),
              title: Text(_error!),
            ),
          ),
        if (_busy) const LinearProgressIndicator(),
        const SectionTitle(
          'Online activation',
          subtitle: 'Use the activation key issued by TablePlay Cloud',
        ),
        Card(
          child: Padding(
            padding: const EdgeInsets.all(20),
            child: Form(
              key: _formKey,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  TextFormField(
                    controller: _cloud,
                    enabled: !_busy,
                    keyboardType: TextInputType.url,
                    decoration: const InputDecoration(
                      labelText: 'TablePlay Cloud URL',
                      hintText: 'https://tableplay-api.onrender.com',
                    ),
                    validator: (value) {
                      final uri = Uri.tryParse(value?.trim() ?? '');
                      return uri != null &&
                              ['http', 'https'].contains(uri.scheme) &&
                              uri.host.isNotEmpty &&
                              uri.userInfo.isEmpty &&
                              (value?.length ?? 0) <= 500
                          ? null
                          : 'Enter a valid TablePlay Cloud address.';
                    },
                  ),
                  const SizedBox(height: 14),
                  TextFormField(
                    controller: _key,
                    enabled: !_busy,
                    obscureText: !_showKey,
                    autocorrect: false,
                    enableSuggestions: false,
                    decoration: InputDecoration(
                      labelText: 'Licence activation key',
                      suffixIcon: IconButton(
                        tooltip: _showKey
                            ? 'Hide licence key'
                            : 'Show licence key',
                        onPressed: () => setState(() => _showKey = !_showKey),
                        icon: Icon(
                          _showKey
                              ? Icons.visibility_off_outlined
                              : Icons.visibility_outlined,
                        ),
                      ),
                    ),
                    validator: (value) =>
                        value != null &&
                            value.trim().isNotEmpty &&
                            value.trim().length <= 100
                        ? null
                        : 'Enter the activation key supplied by TablePlay.',
                  ),
                  const SizedBox(height: 16),
                  Align(
                    alignment: Alignment.centerRight,
                    child: FilledButton.icon(
                      onPressed: _busy
                          ? null
                          : () {
                              if (!_formKey.currentState!.validate()) return;
                              _run(() async {
                                await widget.onActivate(
                                  _cloud.text.trim(),
                                  _key.text.trim(),
                                );
                                _key.clear();
                              });
                            },
                      icon: const Icon(Icons.verified_user_outlined),
                      label: const Text('Activate and verify'),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
        const SectionTitle('Plan features'),
        Card(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Wrap(
              spacing: 8,
              runSpacing: 8,
              children: features.isEmpty
                  ? [const Text('No product features are enabled.')]
                  : features.entries
                        .map(
                          (entry) => Chip(
                            avatar: Icon(
                              entry.value == true && state['licensed'] == true
                                  ? Icons.check_circle_outline
                                  : Icons.lock_outline,
                              size: 18,
                            ),
                            label: Text('${entry.key}'.replaceAll('_', ' ')),
                          ),
                        )
                        .toList(),
            ),
          ),
        ),
        const SectionTitle('Synchronization history'),
        if (logs.isEmpty)
          const Card(
            child: ListTile(title: Text('No synchronization events yet')),
          ),
        ...logs.map(
          (value) => Card(
            child: ListTile(
              title: Text('${value['event']}'.replaceAll('.', ' ')),
              subtitle: Text(licenseDate(value['created_at'])),
              trailing: StatusPill('${value['status']}'),
            ),
          ),
        ),
      ],
    );
  }

  Widget _line(String label, String value) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 6),
    child: Wrap(
      spacing: 12,
      runSpacing: 4,
      children: [
        Text('$label:', style: const TextStyle(fontWeight: FontWeight.w700)),
        SelectableText(value),
      ],
    ),
  );
}

class BillDetailsDialog extends StatelessWidget {
  const BillDetailsDialog({super.key, required this.session});
  final Map session;
  @override
  Widget build(BuildContext context) {
    final bill = session['bill'] as Map? ?? {};
    final orders = (session['orders'] as List? ?? []).where(
      (order) => !['cancelled', 'rejected'].contains(order['status']),
    );
    Widget amount(String label, dynamic value) => Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        children: [
          Expanded(child: Text(label)),
          Text(
            '${value ?? '0.00'}',
            style: const TextStyle(fontWeight: FontWeight.w800),
          ),
        ],
      ),
    );
    return AlertDialog(
      title: Text('${bill['bill_number'] ?? 'Bill summary'}'),
      content: SizedBox(
        width: 500,
        child: SingleChildScrollView(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                '${session['dining_table']?['table_code'] ?? 'Table'} - ${session['session_code'] ?? ''}',
              ),
              const SizedBox(height: 12),
              StatusPill('${bill['payment_status'] ?? 'unpaid'}'),
              const Divider(height: 28),
              for (final order in orders) ...[
                Text(
                  '${order['order_number']}',
                  style: const TextStyle(fontWeight: FontWeight.w800),
                ),
                for (final item in (order['items'] as List? ?? []))
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    title: Text(
                      '${item['quantity']} x ${item['item_name_snapshot']}',
                    ),
                    subtitle: item['special_instruction'] == null
                        ? null
                        : Text('${item['special_instruction']}'),
                  ),
              ],
              const Divider(),
              amount('Subtotal', bill['subtotal']),
              amount('Tax', bill['tax_amount']),
              amount('Discount', bill['discount_amount']),
              amount('Grand total', bill['grand_total']),
              const SizedBox(height: 10),
              const Text(
                'These are server-calculated totals. Closing this preview does not record payment.',
              ),
            ],
          ),
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context),
          child: const Text('Close'),
        ),
      ],
    );
  }
}

class ConnectionQrDialog extends StatefulWidget {
  const ConnectionQrDialog({super.key, required this.payload});
  final Map<String, dynamic> payload;
  @override
  State<ConnectionQrDialog> createState() => _ConnectionQrDialogState();
}

class _ConnectionQrDialogState extends State<ConnectionQrDialog> {
  Timer? _timer;
  @override
  void initState() {
    super.initState();
    _timer = Timer.periodic(const Duration(seconds: 1), (_) {
      if (mounted) setState(() {});
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final pairing = widget.payload['type'] == 'tableplay_pairing';
    final expiry = DateTime.tryParse('${widget.payload['expires_at']}');
    final expired =
        pairing && expiry != null && !expiry.isAfter(DateTime.now());
    final seconds = expiry == null
        ? 0
        : math.max(0, expiry.difference(DateTime.now()).inSeconds);
    final qrSize = math.min(320.0, MediaQuery.sizeOf(context).width - 112);
    return AlertDialog(
      title: Text(
        pairing
            ? 'Pair ${widget.payload['table_code']}'
            : 'Connect Staff Android',
      ),
      content: SizedBox(
        width: 400,
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                '${widget.payload['server_name']}',
                style: const TextStyle(fontWeight: FontWeight.w800),
              ),
              const SizedBox(height: 14),
              if (expired)
                const Padding(
                  padding: EdgeInsets.all(20),
                  child: Text(
                    'This QR has expired. Close it and generate a new one.',
                  ),
                )
              else
                QrImageView(
                  data: jsonEncode(widget.payload),
                  size: qrSize,
                  backgroundColor: Colors.white,
                  padding: const EdgeInsets.all(12),
                  semanticsLabel: pairing
                      ? 'Single-use Customer tablet pairing QR'
                      : 'Staff server connection QR',
                ),
              const SizedBox(height: 14),
              SelectableText(
                '${widget.payload['api_base_url']}',
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 12),
              Text(
                pairing
                    ? 'Private, single-use QR. Scan in Customer app on the same Wi-Fi. Expires in ${seconds ~/ 60}:${(seconds % 60).toString().padLeft(2, '0')}.'
                    : 'Scan from Staff Android. This connects the server only; every staff member must still sign in.',
                textAlign: TextAlign.center,
              ),
            ],
          ),
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context),
          child: const Text('Close'),
        ),
      ],
    );
  }
}
