import 'dart:async';
import 'dart:convert';
import 'dart:math' show max, Random;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:file_picker/file_picker.dart';
import 'package:tableplay_update/tableplay_update.dart';

import '../core/api_client.dart';
import '../core/session_store.dart';
import '../theme/tableplay_theme.dart';
import '../widgets/common.dart';
import 'plan_controls.dart';

class StaffHome extends StatefulWidget {
  const StaffHome({super.key, required this.store, required this.onSignedOut});
  final SessionStore store;
  final VoidCallback onSignedOut;
  @override
  State<StaffHome> createState() => _StaffHomeState();
}

class _StaffDestination {
  const _StaffDestination(this.label, this.subtitle, this.icon, {this.feature});

  final String label;
  final String subtitle;
  final IconData icon;
  final String? feature;
}

class _StaffHomeState extends State<StaffHome> with WidgetsBindingObserver {
  late final ApiClient api;
  dynamic data;
  bool loading = true, refreshing = false;
  String? error;
  Map<String, dynamic> branding = {};
  Map<String, dynamic> entitlements = {};
  DateTime? _brandingLoadedAt;
  DateTime? _lastUpdatedAt;
  Timer? polling;
  int previousAttentionCount = 0;
  int sectionIndex = 0;
  bool _loadInProgress = false;
  bool _reloadRequested = false;
  String? _dataFingerprint;

  String get role => widget.store.role!;
  String get staffName => widget.store.name ?? 'Staff';

  bool _featureAllowed(String feature) {
    return planFeatureAllowed(entitlements, feature);
  }

  bool _destinationLocked(_StaffDestination destination) =>
      destination.feature != null && !_featureAllowed(destination.feature!);

  String get _planName {
    final plan = entitlements['plan'];
    if (plan is Map && plan['name'] != null) return '${plan['name']}';
    return 'current plan';
  }

  List<_StaffDestination> get destinations => switch (role) {
    'admin' => const [
      _StaffDestination(
        'Overview',
        'Complete operations view',
        Icons.dashboard_rounded,
      ),
      _StaffDestination(
        'Tables',
        'Floor and pairing status',
        Icons.table_restaurant_rounded,
      ),
      _StaffDestination('Team', 'Staff access controls', Icons.groups_rounded),
      _StaffDestination(
        'Menu & games',
        'Guest availability',
        Icons.restaurant_menu_rounded,
      ),
      _StaffDestination(
        'Reports',
        'Sales and audit activity',
        Icons.analytics_rounded,
        feature: 'advanced_reports',
      ),
      _StaffDestination(
        'Settings',
        'Restaurant configuration',
        Icons.tune_rounded,
      ),
      _StaffDestination(
        'Automation',
        'Backups, alerts, and maintenance',
        Icons.auto_awesome_motion_rounded,
        feature: 'automation',
      ),
      _StaffDestination(
        'System health',
        'Server and device status',
        Icons.monitor_heart_rounded,
      ),
    ],
    'counter' => const [
      _StaffDestination(
        'Operations',
        'Complete counter view',
        Icons.space_dashboard_rounded,
      ),
      _StaffDestination(
        'Orders',
        'Confirm incoming orders',
        Icons.receipt_long_rounded,
      ),
      _StaffDestination(
        'Guest requests',
        'Coordinate table service',
        Icons.room_service_rounded,
      ),
      _StaffDestination(
        'Billing',
        'Bills and cash payment',
        Icons.point_of_sale_rounded,
      ),
      _StaffDestination(
        'Game timers',
        'Table game access',
        Icons.sports_esports_rounded,
        feature: 'games',
      ),
    ],
    'kitchen' => const [
      _StaffDestination(
        'Kitchen queue',
        'All live tickets',
        Icons.soup_kitchen_rounded,
      ),
      _StaffDestination(
        'New tickets',
        'Waiting to prepare',
        Icons.notifications_active_rounded,
      ),
      _StaffDestination('Preparing', 'Work in progress', Icons.timer_rounded),
      _StaffDestination(
        'Ready',
        'Waiting for service',
        Icons.room_service_rounded,
      ),
    ],
    'waiter' => const [
      _StaffDestination(
        'Floor overview',
        'Complete service view',
        Icons.space_dashboard_rounded,
      ),
      _StaffDestination(
        'Guest requests',
        'Calls, water, and bills',
        Icons.notifications_active_rounded,
      ),
      _StaffDestination(
        'Ready orders',
        'Collect from kitchen',
        Icons.room_service_rounded,
      ),
      _StaffDestination(
        'My floor',
        'Tables and visits',
        Icons.table_bar_rounded,
      ),
    ],
    _ => const [
      _StaffDestination(
        'Workspace',
        'Staff operations',
        Icons.dashboard_rounded,
      ),
    ],
  };

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    api = ApiClient(baseUrl: widget.store.baseUrl, token: widget.store.token);
    load();
    _startPolling();
  }

  void _startPolling() {
    polling?.cancel();
    polling = Timer.periodic(
      Duration(seconds: role == 'admin' ? (sectionIndex == 0 ? 20 : 60) : 7),
      (_) => load(silent: true),
    );
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      _startPolling();
      load(silent: true);
    } else if (state == AppLifecycleState.inactive ||
        state == AppLifecycleState.paused ||
        state == AppLifecycleState.detached) {
      polling?.cancel();
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    polling?.cancel();
    api.close();
    super.dispose();
  }

  Future<void> load({bool silent = false, bool force = false}) async {
    if (_loadInProgress) {
      if (force || !silent) _reloadRequested = true;
      return;
    }
    _loadInProgress = true;
    final requestedSection = sectionIndex;
    if (!silent && mounted) setState(() => refreshing = true);
    try {
      final refreshBranding =
          _brandingLoadedAt == null ||
          DateTime.now().difference(_brandingLoadedAt!).inMinutes >= 5;
      final selectedWasLocked = _destinationLocked(destinations[sectionIndex]);
      final sectionPath = switch (role) {
        'admin' =>
          selectedWasLocked
              ? '/admin/dashboard'
              : switch (sectionIndex) {
                  1 => '/admin/tables',
                  2 => '/admin/team',
                  3 => '/admin/catalog',
                  4 => '/admin/reports',
                  5 => '/admin/settings',
                  6 => '/admin/automation',
                  7 => '/admin/system',
                  _ => '/admin/dashboard',
                },
        'counter' => '/counter/dashboard',
        'kitchen' => '/kitchen/orders',
        'waiter' => '/waiter/dashboard',
        _ => '/auth/me',
      };
      final responses = await Future.wait<dynamic>([
        api.get(sectionPath),
        if (refreshBranding)
          api.get('/branding').catchError((_) => null)
        else
          Future<dynamic>.value(null),
      ]);
      final next = responses.first;
      if (responses[1] is Map) {
        branding = Map<String, dynamic>.from(responses[1] as Map);
        _brandingLoadedAt = DateTime.now();
      }
      if (next is Map && next['entitlements'] is Map) {
        entitlements = Map<String, dynamic>.from(next['entitlements'] as Map);
        if (role == 'admin' &&
            selectedWasLocked &&
            !_destinationLocked(destinations[sectionIndex])) {
          setState(() {
            sectionIndex = 0;
            loading = true;
          });
          _reloadRequested = true;
          return;
        }
      }
      final count = role == 'kitchen'
          ? (next as List).where((item) => item['status'] == 'confirmed').length
          : role == 'counter'
          ? (next['orders'] as List)
                .where((item) => item['status'] == 'pending')
                .length
          : role == 'waiter'
          ? (next['requests'] as List)
                .where((item) => item['status'] == 'pending')
                .length
          : 0;
      if (role == 'admin' && requestedSection != sectionIndex) {
        _reloadRequested = true;
        return;
      }
      if (previousAttentionCount > 0 && count > previousAttentionCount) {
        SystemSound.play(SystemSoundType.alert);
      }
      previousAttentionCount = count;
      final fingerprint = jsonEncode(next);
      if (mounted && (!silent || fingerprint != _dataFingerprint)) {
        setState(() {
          data = next;
          loading = false;
          error = null;
          _lastUpdatedAt = DateTime.now();
        });
      }
      _dataFingerprint = fingerprint;
    } catch (exception) {
      if (exception is ApiException && exception.status == 401) {
        if (mounted) {
          setState(() {
            error =
                'The local server could not verify this saved session. Your login is still saved—check the server connection and retry, or use Sign out to remove it from this device.';
            loading = false;
          });
        }
        return;
      }
      final selected = destinations[sectionIndex];
      if (exception is ApiException &&
          exception.status == 403 &&
          (exception.isPlanRestriction || selected.feature != null)) {
        final feature = exception.feature ?? selected.feature;
        if (feature != null) {
          final features = entitlements['features'] is Map
              ? Map<String, dynamic>.from(entitlements['features'] as Map)
              : <String, dynamic>{};
          features[feature] = false;
          entitlements = {
            ...entitlements,
            'features': features,
            if (exception.details['plan'] != null)
              'plan': {
                'slug': exception.details['plan'],
                'name':
                    exception.details['plan_name'] ?? exception.details['plan'],
              },
            if (exception.details['license_status'] != null)
              'status': exception.details['license_status'],
          };
        }
        if (mounted) {
          setState(() {
            error = null;
            loading = false;
          });
        }
        return;
      }
      if (mounted && !silent) {
        setState(() {
          error = exception.toString();
          loading = false;
        });
      }
    } finally {
      _loadInProgress = false;
      if (mounted && refreshing) setState(() => refreshing = false);
      if (mounted && _reloadRequested) {
        _reloadRequested = false;
        Future.microtask(() => load(silent: true, force: true));
      }
    }
  }

  Future<void> act(
    String path, {
    Map<String, dynamic>? body,
    String success = 'Updated successfully.',
  }) async {
    try {
      await api.post(path, body);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(success),
            backgroundColor: TablePlayColors.success,
          ),
        );
      }
      await load(silent: true, force: true);
    } catch (exception) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(exception.toString()),
            backgroundColor: TablePlayColors.danger,
          ),
        );
      }
    }
  }

  Future<void> put(
    String path,
    Map<String, dynamic> body,
    String success,
  ) async {
    try {
      await api.put(path, body);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(success),
            backgroundColor: TablePlayColors.success,
          ),
        );
      }
      await load(silent: true, force: true);
    } catch (exception) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(exception.toString()),
            backgroundColor: TablePlayColors.danger,
          ),
        );
      }
    }
  }

  Future<bool> confirm(
    String title,
    String message, {
    String action = 'Confirm',
    bool danger = false,
  }) async =>
      await showDialog<bool>(
        context: context,
        builder: (dialogContext) => AlertDialog(
          icon: Icon(
            danger ? Icons.warning_amber_rounded : Icons.help_outline_rounded,
            color: danger ? TablePlayColors.danger : TablePlayColors.accent,
            size: 34,
          ),
          title: Text(title, textAlign: TextAlign.center),
          content: Text(message, textAlign: TextAlign.center),
          actionsAlignment: MainAxisAlignment.center,
          actions: [
            OutlinedButton(
              onPressed: () => Navigator.pop(dialogContext, false),
              child: const Text('Cancel'),
            ),
            FilledButton(
              onPressed: () => Navigator.pop(dialogContext, true),
              style: danger
                  ? FilledButton.styleFrom(
                      backgroundColor: TablePlayColors.danger,
                    )
                  : null,
              child: Text(action),
            ),
          ],
        ),
      ) ??
      false;

  Future<String?> ask(
    String title,
    String label, {
    String initial = '',
    TextInputType keyboard = TextInputType.text,
  }) async {
    final controller = TextEditingController(text: initial);
    final value = await showDialog<String>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: Text(title),
        content: TextField(
          controller: controller,
          keyboardType: keyboard,
          autofocus: true,
          maxLines: keyboard == TextInputType.text ? 2 : 1,
          decoration: InputDecoration(labelText: label),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogContext),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () =>
                Navigator.pop(dialogContext, controller.text.trim()),
            child: const Text('Continue'),
          ),
        ],
      ),
    );
    controller.dispose();
    return value?.isEmpty == true ? null : value;
  }

  Future<void> logout() async {
    try {
      await api.post('/auth/logout');
    } catch (_) {}
    await widget.store.clear();
    widget.onSignedOut();
  }

  @override
  Widget build(BuildContext context) => LayoutBuilder(
    builder: (context, constraints) {
      final wide = constraints.maxWidth >= 980;
      final destination = destinations[sectionIndex];
      final destinationLocked = _destinationLocked(destination);
      final content = loading
          ? const Center(child: CircularProgressIndicator())
          : error != null
          ? _error()
          : RefreshIndicator(
              onRefresh: load,
              child: destinationLocked
                  ? _lockedFeature(destination)
                  : switch (role) {
                      'admin' => _admin(),
                      'counter' => _counter(),
                      'kitchen' => _kitchen(),
                      'waiter' => _waiter(),
                      _ => _error(),
                    },
            );

      return Scaffold(
        drawer: wide
            ? null
            : Drawer(child: _navigationPanel(closeDrawer: true)),
        appBar: AppBar(
          titleSpacing: wide ? 24 : 6,
          title: wide
              ? Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      destination.label,
                      style: const TextStyle(
                        fontSize: 18,
                        fontWeight: FontWeight.w900,
                      ),
                    ),
                    Text(
                      destination.subtitle,
                      style: const TextStyle(
                        fontSize: 10,
                        color: Colors.white60,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ],
                )
              : TablePlayBrand(
                  light: true,
                  logoUrl: '${branding['app_logo_url'] ?? ''}',
                  title: '${branding['restaurant_name'] ?? 'TablePlay'}',
                ),
          actions: [
            if (refreshing)
              const Padding(
                padding: EdgeInsets.all(18),
                child: SizedBox.square(
                  dimension: 17,
                  child: CircularProgressIndicator(
                    strokeWidth: 2,
                    color: Colors.white,
                  ),
                ),
              ),
            if (!wide)
              IconButton(
                onPressed: () => load(),
                icon: const Icon(Icons.refresh_rounded),
                tooltip: 'Refresh workspace',
              ),
            PopupMenuButton<String>(
              tooltip: 'Account menu',
              icon: CircleAvatar(
                radius: 16,
                backgroundColor: Colors.white.withValues(alpha: .16),
                child: Text(
                  staffName.substring(0, 1).toUpperCase(),
                  style: const TextStyle(
                    color: Colors.white,
                    fontWeight: FontWeight.w900,
                  ),
                ),
              ),
              onSelected: (value) async {
                if (value == 'logout' &&
                    await confirm(
                      'Sign out?',
                      'Your local staff session will be closed on this device.',
                      action: 'Sign out',
                    )) {
                  logout();
                }
              },
              itemBuilder: (_) => [
                PopupMenuItem(
                  enabled: false,
                  child: Text(
                    '$staffName\n${role.toUpperCase()}',
                    style: const TextStyle(fontWeight: FontWeight.w700),
                  ),
                ),
                const PopupMenuDivider(),
                const PopupMenuItem(
                  value: 'logout',
                  child: ListTile(
                    leading: Icon(Icons.logout_rounded),
                    title: Text('Sign out'),
                    contentPadding: EdgeInsets.zero,
                  ),
                ),
              ],
            ),
            const SizedBox(width: 6),
          ],
        ),
        body: Row(
          children: [
            if (wide) SizedBox(width: 282, child: _navigationPanel()),
            if (wide) const VerticalDivider(width: 1),
            Expanded(
              child: Column(
                children: [
                  OperationalContextBar(
                    role: role,
                    serverHost:
                        Uri.tryParse(widget.store.baseUrl)?.host ??
                        widget.store.baseUrl,
                    planName: _planName,
                    online: error == null,
                    refreshing: refreshing,
                    lastUpdatedAt: _lastUpdatedAt,
                    onRefresh: () => load(),
                  ),
                  Expanded(child: content),
                ],
              ),
            ),
          ],
        ),
        floatingActionButton: FloatingActionButton.extended(
          onPressed: _openHelp,
          backgroundColor: TablePlayColors.accent,
          foregroundColor: Colors.white,
          icon: const Icon(Icons.smart_toy_rounded),
          label: const Text('Help'),
        ),
      );
    },
  );

  Widget _navigationPanel({bool closeDrawer = false}) => ColoredBox(
    color: TablePlayColors.deep,
    child: SafeArea(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: EdgeInsets.fromLTRB(20, 20, 20, 18),
            child: TablePlayBrand(
              light: true,
              logoUrl: '${branding['app_logo_url'] ?? ''}',
              title: '${branding['restaurant_name'] ?? 'TablePlay'}',
            ),
          ),
          Container(
            margin: const EdgeInsets.fromLTRB(14, 0, 14, 14),
            padding: const EdgeInsets.all(13),
            decoration: BoxDecoration(
              color: Colors.white.withValues(alpha: .08),
              borderRadius: BorderRadius.circular(16),
              border: Border.all(color: Colors.white.withValues(alpha: .08)),
            ),
            child: Row(
              children: [
                CircleAvatar(
                  backgroundColor: TablePlayColors.accent,
                  foregroundColor: Colors.white,
                  child: Text(
                    staffName.substring(0, 1).toUpperCase(),
                    style: const TextStyle(fontWeight: FontWeight.w900),
                  ),
                ),
                const SizedBox(width: 11),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        staffName,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          color: Colors.white,
                          fontWeight: FontWeight.w900,
                        ),
                      ),
                      Text(
                        '${role.toUpperCase()} WORKSPACE',
                        style: const TextStyle(
                          color: Colors.white60,
                          fontSize: 9,
                          letterSpacing: .8,
                          fontWeight: FontWeight.w800,
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
          const Padding(
            padding: EdgeInsets.fromLTRB(20, 3, 20, 7),
            child: Text(
              'NAVIGATION',
              style: TextStyle(
                color: Colors.white38,
                fontSize: 9,
                letterSpacing: 1.2,
                fontWeight: FontWeight.w900,
              ),
            ),
          ),
          Expanded(
            child: ListView.builder(
              padding: const EdgeInsets.symmetric(horizontal: 10),
              itemCount: destinations.length,
              itemBuilder: (context, index) {
                final item = destinations[index];
                final selected = sectionIndex == index;
                final locked = _destinationLocked(item);
                return Padding(
                  padding: const EdgeInsets.only(bottom: 4),
                  child: ListTile(
                    selected: selected,
                    selectedTileColor: Colors.white.withValues(alpha: .12),
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(13),
                    ),
                    leading: Icon(
                      locked ? Icons.lock_outline_rounded : item.icon,
                      color: locked
                          ? Colors.white38
                          : selected
                          ? TablePlayColors.gold
                          : Colors.white70,
                    ),
                    title: Text(
                      item.label,
                      style: TextStyle(
                        color: locked ? Colors.white54 : Colors.white,
                        fontWeight: selected
                            ? FontWeight.w900
                            : FontWeight.w700,
                      ),
                    ),
                    subtitle: Text(
                      locked ? 'Not included in $_planName' : item.subtitle,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        color: Colors.white54,
                        fontSize: 10,
                      ),
                    ),
                    trailing: locked
                        ? const Icon(
                            Icons.workspace_premium_outlined,
                            color: TablePlayColors.gold,
                            size: 18,
                          )
                        : null,
                    onTap: () {
                      if (sectionIndex != index) {
                        setState(() {
                          sectionIndex = index;
                          if (role == 'admin' && !locked) loading = true;
                        });
                        _startPolling();
                        _dataFingerprint = null;
                        load(force: true);
                      }
                      if (closeDrawer) Navigator.pop(context);
                    },
                  ),
                );
              },
            ),
          ),
          Container(
            margin: const EdgeInsets.all(12),
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: Colors.black.withValues(alpha: .12),
              borderRadius: BorderRadius.circular(14),
            ),
            child: Row(
              children: [
                Icon(
                  error == null ? Icons.lan_rounded : Icons.cloud_off_rounded,
                  color: error == null
                      ? TablePlayColors.gold
                      : Colors.redAccent,
                  size: 20,
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        error == null
                            ? 'Local server online'
                            : 'Connection issue',
                        style: const TextStyle(
                          color: Colors.white,
                          fontSize: 11,
                          fontWeight: FontWeight.w800,
                        ),
                      ),
                      Text(
                        Uri.tryParse(widget.store.baseUrl)?.host ??
                            widget.store.baseUrl,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          color: Colors.white54,
                          fontSize: 9,
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(10, 0, 10, 2),
            child: TextButton.icon(
              onPressed: () => LocalUpdateGate.check(context),
              icon: const Icon(Icons.system_update_rounded),
              label: const Text('Check for app updates'),
              style: TextButton.styleFrom(foregroundColor: Colors.white70),
            ),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(10, 0, 10, 12),
            child: TextButton.icon(
              onPressed: () async {
                if (closeDrawer) Navigator.pop(context);
                if (await confirm(
                  'Sign out?',
                  'Your local staff session will be closed on this device.',
                  action: 'Sign out',
                )) {
                  logout();
                }
              },
              icon: const Icon(Icons.logout_rounded),
              label: const Text('Sign out'),
              style: TextButton.styleFrom(foregroundColor: Colors.white70),
            ),
          ),
        ],
      ),
    ),
  );

  Future<void> _openHelp() async {
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (_) => _StaffHelpSheet(
        api: api,
        role: role,
        onNavigate: (url) {
          Navigator.pop(context);
          final path = Uri.tryParse(url ?? '')?.path ?? '';
          final index = role == 'admin'
              ? switch (path) {
                  '/admin/tables' => 1,
                  '/admin/team' => 2,
                  '/admin/menu' || '/admin/games' => 3,
                  '/admin/reports' => 4,
                  '/admin/settings' => 5,
                  '/admin/automation' => 6,
                  '/admin/system' => 7,
                  _ => 0,
                }
              : 0;
          if (index != sectionIndex) {
            setState(() {
              sectionIndex = index;
              if (role == 'admin') loading = true;
            });
            _startPolling();
            _dataFingerprint = null;
            load(force: true);
          }
        },
      ),
    );
  }

  Widget _error() => ListView(
    padding: const EdgeInsets.all(24),
    children: [
      const SizedBox(height: 80),
      const Icon(
        Icons.cloud_off_rounded,
        size: 60,
        color: TablePlayColors.muted,
      ),
      const SizedBox(height: 16),
      Text(error ?? 'Unsupported staff role', textAlign: TextAlign.center),
      const SizedBox(height: 16),
      Center(
        child: FilledButton.icon(
          onPressed: load,
          icon: const Icon(Icons.refresh),
          label: const Text('Try again'),
        ),
      ),
    ],
  );

  Widget _lockedFeature(_StaffDestination destination) => ListView(
    padding: const EdgeInsets.all(24),
    children: [
      const SizedBox(height: 54),
      Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 580),
          child: Card(
            child: Padding(
              padding: const EdgeInsets.all(28),
              child: Column(
                children: [
                  Container(
                    width: 72,
                    height: 72,
                    decoration: BoxDecoration(
                      color: TablePlayColors.gold.withValues(alpha: .14),
                      borderRadius: BorderRadius.circular(22),
                    ),
                    child: const Icon(
                      Icons.workspace_premium_rounded,
                      color: TablePlayColors.accent,
                      size: 38,
                    ),
                  ),
                  const SizedBox(height: 20),
                  Text(
                    '${destination.label} is locked',
                    textAlign: TextAlign.center,
                    style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                      fontWeight: FontWeight.w900,
                    ),
                  ),
                  const SizedBox(height: 9),
                  Text(
                    'This feature is not included in $_planName, or the restaurant licence needs renewal. Core restaurant operations remain available.',
                    textAlign: TextAlign.center,
                    style: const TextStyle(
                      color: TablePlayColors.muted,
                      height: 1.5,
                    ),
                  ),
                  const SizedBox(height: 18),
                  Chip(
                    avatar: const Icon(Icons.verified_user_outlined, size: 17),
                    label: Text(
                      '${entitlements['status'] ?? 'Unavailable'} · $_planName',
                    ),
                  ),
                  const SizedBox(height: 20),
                  FilledButton.icon(
                    onPressed: () => load(force: true),
                    icon: const Icon(Icons.refresh_rounded),
                    label: const Text('Check plan again'),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    ],
  );

  Widget _page(List<Widget> children) => LayoutBuilder(
    builder: (context, constraints) => ListView(
      padding: EdgeInsets.fromLTRB(
        constraints.maxWidth >= 900 ? 28 : 14,
        20,
        constraints.maxWidth >= 900 ? 28 : 14,
        90,
      ),
      children: [
        Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 1180),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  destinations[sectionIndex].label,
                  style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                    fontWeight: FontWeight.w900,
                  ),
                ),
                Text(
                  '${destinations[sectionIndex].subtitle} · $staffName',
                  style: const TextStyle(color: TablePlayColors.muted),
                ),
                ...children,
              ],
            ),
          ),
        ),
      ],
    ),
  );

  Widget _statsGrid(List<Widget> children) => GridView.builder(
    gridDelegate: const SliverGridDelegateWithMaxCrossAxisExtent(
      maxCrossAxisExtent: 300,
      mainAxisExtent: 132,
      crossAxisSpacing: 10,
      mainAxisSpacing: 10,
    ),
    itemCount: children.length,
    shrinkWrap: true,
    physics: const NeverScrollableScrollPhysics(),
    itemBuilder: (_, index) => children[index],
  );

  Widget _admin() => switch (sectionIndex) {
    1 => _adminTables(),
    2 => _adminTeam(),
    3 => _adminCatalog(),
    4 => _adminReports(),
    5 => _adminSettings(),
    6 => _adminAutomation(),
    7 => _adminSystem(),
    _ => _adminDashboard(),
  };

  Widget _adminDashboard() {
    final view = data as Map;
    final stats = view['stats'] as Map;
    final tables = view['tables'] as List? ?? [];
    final orders = view['recent_orders'] as List? ?? [];
    final requests = view['requests'] as List? ?? [];
    final devices = view['devices'] as List? ?? [];
    return _page([
      const SectionTitle('Today at a glance'),
      _statsGrid([
        StatTile(
          label: 'Sales today',
          value: '₹${stats['sales_today']}',
          icon: Icons.currency_rupee_rounded,
        ),
        StatTile(
          label: 'Orders today',
          value: '${stats['orders_today']}',
          icon: Icons.receipt_long_rounded,
        ),
        StatTile(
          label: 'Open tables',
          value: '${stats['open_tables']}',
          icon: Icons.table_restaurant_rounded,
        ),
        StatTile(
          label: 'Pending orders',
          value: '${stats['pending_orders']}',
          icon: Icons.notifications_active_rounded,
        ),
      ]),
      SectionTitle('Live floor', subtitle: '${tables.length} tables'),
      LayoutBuilder(
        builder: (context, constraints) {
          const gap = 10.0;
          final columns = constraints.maxWidth >= 1040
              ? 4
              : constraints.maxWidth >= 760
              ? 3
              : constraints.maxWidth >= 500
              ? 2
              : 1;
          final cardWidth =
              (constraints.maxWidth - (gap * (columns - 1))) / columns;
          return Wrap(
            spacing: gap,
            runSpacing: gap,
            children: tables.map((value) {
              final table = value as Map;
              final sessions = table['sessions'] as List? ?? [];
              final occupied = sessions.isNotEmpty;
              return SizedBox(
                width: cardWidth,
                height: 112,
                child: Card(
                  child: Padding(
                    padding: const EdgeInsets.all(13),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          children: [
                            CircleAvatar(
                              radius: 20,
                              child: FittedBox(
                                fit: BoxFit.scaleDown,
                                child: Text('${table['table_code']}'),
                              ),
                            ),
                            const SizedBox(width: 10),
                            Expanded(
                              child: Text(
                                '${table['table_name']}',
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: const TextStyle(
                                  fontWeight: FontWeight.w900,
                                  fontSize: 14,
                                ),
                              ),
                            ),
                          ],
                        ),
                        const Spacer(),
                        Row(
                          children: [
                            Icon(
                              occupied
                                  ? Icons.groups_2_outlined
                                  : Icons.event_seat_outlined,
                              size: 16,
                              color: TablePlayColors.muted,
                            ),
                            const SizedBox(width: 6),
                            Expanded(
                              child: Text(
                                occupied
                                    ? '${sessions.first['guest_count']} guests'
                                    : '${table['capacity']} seats',
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: const TextStyle(
                                  color: TablePlayColors.muted,
                                  fontSize: 11,
                                  fontWeight: FontWeight.w700,
                                ),
                              ),
                            ),
                            const SizedBox(width: 6),
                            StatusPill('${table['status']}'),
                          ],
                        ),
                      ],
                    ),
                  ),
                ),
              );
            }).toList(),
          );
        },
      ),
      SectionTitle('Recent orders', subtitle: '${orders.length} shown'),
      if (orders.isEmpty)
        _empty('No orders yet')
      else
        ...orders.map((value) => _order(value as Map)),
      SectionTitle('Guest requests', subtitle: '${requests.length} active'),
      if (requests.isEmpty)
        _empty('No active guest requests')
      else
        ...requests.map((value) {
          final request = value as Map;
          return Card(
            child: ListTile(
              leading: const Icon(
                Icons.room_service_rounded,
                color: TablePlayColors.accent,
              ),
              title: Text(
                '${tableCode(request)} · ${request['request_type']}'.replaceAll(
                  '_',
                  ' ',
                ),
                style: const TextStyle(fontWeight: FontWeight.w900),
              ),
              subtitle: Text('${request['message'] ?? 'No message'}'),
              trailing: StatusPill('${request['status']}'),
            ),
          );
        }),
      SectionTitle(
        'Recently seen tablets',
        subtitle: '${devices.length} shown',
      ),
      ...devices.map((value) {
        final device = value as Map;
        return Card(
          child: ListTile(
            leading: const Icon(Icons.tablet_android_rounded),
            title: Text(
              '${device['device_name']}',
              style: const TextStyle(fontWeight: FontWeight.w800),
            ),
            subtitle: Text('Last seen ${device['last_seen_at'] ?? 'never'}'),
            trailing: StatusPill(
              device['is_active'] == true ? 'active' : 'disabled',
            ),
          ),
        );
      }),
    ]);
  }

  Widget _adminTables() {
    final view = data as Map;
    final tables = view['tables'] as List? ?? [];
    final devices = view['devices'] as List? ?? [];
    final pairingControl = PairingControlState.evaluate(
      entitlements: entitlements,
      devices: devices,
      tables: tables,
    );
    return _page([
      _adminHeading('Dining tables', '${tables.length} configured', [
        OutlinedButton.icon(
          onPressed: pairingControl.canPair
              ? () => _pairDevice(devices, tables)
              : null,
          icon: Icon(
            pairingControl.isPlanBlocked
                ? Icons.lock_outline_rounded
                : Icons.link_rounded,
          ),
          label: Text(
            pairingControl.quotaAvailable ? 'Pair tablet' : 'Pairing locked',
          ),
        ),
        FilledButton.icon(
          onPressed: _addTable,
          icon: const Icon(Icons.add_rounded),
          label: const Text('Add table'),
        ),
      ]),
      if (!pairingControl.canPair)
        Card(
          color: pairingControl.isPlanBlocked
              ? TablePlayColors.gold.withValues(alpha: .10)
              : null,
          child: ListTile(
            leading: Icon(
              pairingControl.isPlanBlocked
                  ? Icons.workspace_premium_outlined
                  : Icons.info_outline_rounded,
              color: pairingControl.isPlanBlocked
                  ? TablePlayColors.accent
                  : TablePlayColors.muted,
            ),
            title: Text(
              pairingControl.isPlanBlocked
                  ? 'Tablet pairing unavailable'
                  : 'Tablet pairing needs attention',
              style: const TextStyle(fontWeight: FontWeight.w900),
            ),
            subtitle: Text(pairingControl.message),
          ),
        ),
      ...tables.map((value) {
        final table = value as Map;
        final pairings = table['pairings'] as List? ?? [];
        final pairing = pairings.isEmpty ? null : pairings.first as Map;
        return Card(
          child: Padding(
            padding: const EdgeInsets.all(8),
            child: ListTile(
              leading: CircleAvatar(child: Text('${table['table_code']}')),
              title: Text(
                '${table['table_name']}',
                style: const TextStyle(fontWeight: FontWeight.w900),
              ),
              subtitle: Text(
                '${table['capacity']} seats · ${table['sessions_count']} visits · ${pairing == null ? 'No tablet paired' : pairing['device']?['device_name'] ?? 'Tablet paired'}',
              ),
              trailing: Wrap(
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  StatusPill('${table['status']}'),
                  if (pairing != null)
                    IconButton(
                      tooltip: 'Unpair tablet',
                      onPressed: () async {
                        if (await confirm(
                          'Unpair tablet?',
                          'The customer app will stop controlling ${table['table_code']}.',
                          action: 'Unpair',
                          danger: true,
                        )) {
                          act(
                            '/admin/pairings/${pairing['id']}/unpair',
                            success: 'Tablet unpaired.',
                          );
                        }
                      },
                      icon: const Icon(Icons.link_off_rounded),
                    ),
                  IconButton(
                    tooltip: 'Edit table',
                    onPressed: () => _editTable(table),
                    icon: const Icon(Icons.edit_outlined),
                  ),
                  IconButton(
                    tooltip: 'Remove table',
                    onPressed: () async {
                      if (await confirm(
                        'Remove ${table['table_code']}?',
                        'Unused tables are deleted. Tables with history are safely archived.',
                        action: 'Remove table',
                        danger: true,
                      )) {
                        _delete(
                          '/admin/tables/${table['id']}',
                          'Table removed safely.',
                        );
                      }
                    },
                    icon: const Icon(
                      Icons.delete_outline_rounded,
                      color: TablePlayColors.danger,
                    ),
                  ),
                ],
              ),
            ),
          ),
        );
      }),
      SectionTitle('Registered tablets', subtitle: '${devices.length} devices'),
      if (devices.isEmpty) _empty('No customer tablets registered'),
      ...devices.map((value) {
        final device = value as Map;
        final pairings = device['pairings'] as List? ?? [];
        return Card(
          child: ListTile(
            leading: const Icon(
              Icons.tablet_android_rounded,
              color: TablePlayColors.deep,
            ),
            title: Text(
              '${device['device_name']}',
              style: const TextStyle(fontWeight: FontWeight.w800),
            ),
            subtitle: Text(
              pairings.isEmpty
                  ? 'Available to pair · last seen ${device['last_seen_at'] ?? 'never'}'
                  : 'Paired to ${pairings.first['dining_table']?['table_code'] ?? 'table'}',
            ),
            trailing: StatusPill(pairings.isEmpty ? 'unpaired' : 'paired'),
          ),
        );
      }),
    ]);
  }

  Widget _adminTeam() {
    final view = data as Map;
    final staff = view['staff'] as List? ?? [];
    final roles = view['roles'] as List? ?? [];
    return _page([
      _adminHeading(
        'Staff accounts',
        '${staff.where((value) => value['is_active'] == true).length} active',
        [
          FilledButton.icon(
            onPressed: () => _addStaff(roles),
            icon: const Icon(Icons.person_add_alt_1_rounded),
            label: const Text('Add staff'),
          ),
        ],
      ),
      ...staff.map((value) {
        final user = value as Map;
        return Card(
          child: SwitchListTile(
            secondary: CircleAvatar(
              child: Text('${user['name']}'.substring(0, 1).toUpperCase()),
            ),
            value: user['is_active'] == true,
            onChanged: user['id'] == widget.store.userId
                ? null
                : (_) async {
                    if (await confirm(
                      '${user['is_active'] == true ? 'Disable' : 'Enable'} ${user['name']}?',
                      'This changes access for the ${user['role']['display_name']} account.',
                      danger: user['is_active'] == true,
                    )) {
                      act(
                        '/admin/staff/${user['id']}/toggle',
                        success: 'Staff access updated.',
                      );
                    }
                  },
            title: Text(
              '${user['name']}',
              style: const TextStyle(fontWeight: FontWeight.w900),
            ),
            subtitle: Text(
              '${user['role']['display_name']} · @${user['username']}\n${user['email'] ?? user['mobile'] ?? 'Local account'}',
            ),
            isThreeLine: true,
          ),
        );
      }),
    ]);
  }

  Widget _adminCatalog() {
    final view = data as Map;
    final categories = view['categories'] as List? ?? [];
    final games = view['games'] as List? ?? [];
    final itemCount = categories.fold<int>(
      0,
      (sum, category) => sum + ((category['menu_items'] as List?)?.length ?? 0),
    );
    return _page([
      _adminHeading(
        'Menu catalog',
        '$itemCount items in ${categories.length} categories',
        [
          OutlinedButton.icon(
            onPressed: _addCategory,
            icon: const Icon(Icons.create_new_folder_outlined),
            label: const Text('Category'),
          ),
          FilledButton.icon(
            onPressed: () => _addMenuItem(categories),
            icon: const Icon(Icons.add_rounded),
            label: const Text('Menu item'),
          ),
        ],
      ),
      ...categories.map((value) {
        final category = value as Map;
        final items = category['menu_items'] as List? ?? [];
        return Card(
          child: ExpansionTile(
            initiallyExpanded: true,
            title: Text(
              '${category['name']}',
              style: const TextStyle(fontWeight: FontWeight.w900),
            ),
            subtitle: Text('${items.length} items'),
            children: items.isEmpty
                ? [
                    const ListTile(
                      title: Text('No menu items in this category'),
                    ),
                  ]
                : items.map((value) {
                    final item = value as Map;
                    final imageUrl = '${item['image_url'] ?? ''}';
                    final offer = item['discount_price'];
                    return ListTile(
                      leading: ClipRRect(
                        borderRadius: BorderRadius.circular(12),
                        child: imageUrl.isEmpty
                            ? const SizedBox.square(
                                dimension: 58,
                                child: ColoredBox(
                                  color: Color(0xFFFFF1E8),
                                  child: Icon(Icons.restaurant_menu_rounded),
                                ),
                              )
                            : Image.network(
                                imageUrl,
                                width: 58,
                                height: 58,
                                fit: BoxFit.cover,
                                errorBuilder: (_, __, ___) =>
                                    const SizedBox.square(
                                      dimension: 58,
                                      child: ColoredBox(
                                        color: Color(0xFFFFF1E8),
                                        child: Icon(
                                          Icons.restaurant_menu_rounded,
                                        ),
                                      ),
                                    ),
                              ),
                      ),
                      title: Row(
                        children: [
                          Expanded(
                            child: Text(
                              '${item['name']}',
                              style: const TextStyle(
                                fontWeight: FontWeight.w800,
                              ),
                            ),
                          ),
                          if (item['is_bestseller'] == true)
                            const Chip(label: Text('Bestseller')),
                        ],
                      ),
                      subtitle: Text(
                        '${item['short_description'] ?? item['description'] ?? 'No description'}\n₹${offer ?? item['price']} · ${item['food_type']} · ${item['spice_level'] ?? 'none'} · ${item['preparation_minutes'] ?? '—'} min',
                        maxLines: 3,
                        overflow: TextOverflow.ellipsis,
                      ),
                      isThreeLine: true,
                      trailing: Wrap(
                        crossAxisAlignment: WrapCrossAlignment.center,
                        children: [
                          IconButton(
                            tooltip: 'Upload item photo',
                            onPressed: () => _uploadMenuPhoto(item),
                            icon: const Icon(
                              Icons.add_photo_alternate_outlined,
                            ),
                          ),
                          IconButton(
                            tooltip: 'Edit item',
                            onPressed: () => _editMenuItem(item, categories),
                            icon: const Icon(Icons.edit_outlined),
                          ),
                          Switch(
                            value: item['is_available'] == true,
                            onChanged: (_) => act(
                              '/admin/menu-items/${item['id']}/toggle',
                              success: 'Menu availability updated.',
                            ),
                          ),
                        ],
                      ),
                    );
                  }).toList(),
          ),
        );
      }),
      if (_featureAllowed('games')) ...[
        _adminHeading('Game lounge', '${games.length} configured', [
          FilledButton.icon(
            onPressed: _addGame,
            icon: const Icon(Icons.add_rounded),
            label: const Text('Add game'),
          ),
        ]),
        ...games.map((value) {
          final game = value as Map;
          return Card(
            child: SwitchListTile(
              secondary: const Icon(
                Icons.sports_esports_rounded,
                color: TablePlayColors.accent,
              ),
              value: game['is_active'] == true,
              onChanged: (_) => act(
                '/admin/games/${game['id']}/toggle',
                success: 'Game status updated.',
              ),
              title: Text(
                '${game['name']}',
                style: const TextStyle(fontWeight: FontWeight.w900),
              ),
              subtitle: Text(
                '${game['player_mode']} player · ${game['slug']}\n${game['description'] ?? ''}',
              ),
              isThreeLine: true,
            ),
          );
        }),
      ] else
        Card(
          child: ListTile(
            leading: const Icon(
              Icons.lock_outline_rounded,
              color: TablePlayColors.accent,
            ),
            title: const Text(
              'Game lounge is locked',
              style: TextStyle(fontWeight: FontWeight.w900),
            ),
            subtitle: Text(
              'Games are not included in $_planName. Menu management remains available.',
            ),
            trailing: const Icon(Icons.workspace_premium_outlined),
          ),
        ),
    ]);
  }

  Widget _adminReports() {
    final view = data as Map;
    final summary = view['summary'] as Map;
    final sales = view['sales'] as List? ?? [];
    final audits = view['audits'] as List? ?? [];
    return _page([
      const SectionTitle('All-time summary'),
      _statsGrid([
        StatTile(
          label: 'Revenue',
          value: '₹${summary['revenue']}',
          icon: Icons.currency_rupee_rounded,
        ),
        StatTile(
          label: 'Payments',
          value: '${summary['payments']}',
          icon: Icons.payments_outlined,
        ),
        StatTile(
          label: 'Orders',
          value: '${summary['orders']}',
          icon: Icons.receipt_long_rounded,
        ),
        StatTile(
          label: 'Average order',
          value: '₹${_asDouble(summary['average']).toStringAsFixed(2)}',
          icon: Icons.trending_up_rounded,
        ),
      ]),
      SectionTitle('Daily sales', subtitle: '${sales.length} days'),
      ...sales.map((value) {
        final sale = value as Map;
        return Card(
          child: ListTile(
            leading: const Icon(Icons.calendar_today_rounded),
            title: Text(
              '${sale['sale_date']}',
              style: const TextStyle(fontWeight: FontWeight.w900),
            ),
            subtitle: Text('${sale['orders_count']} orders'),
            trailing: Text(
              '₹${sale['total']}',
              style: const TextStyle(
                fontWeight: FontWeight.w900,
                color: TablePlayColors.success,
              ),
            ),
          ),
        );
      }),
      SectionTitle(
        'Audit activity',
        subtitle: '${audits.length} latest entries',
      ),
      ...audits.map((value) {
        final audit = value as Map;
        return Card(
          child: ListTile(
            leading: const Icon(Icons.history_rounded),
            title: Text(
              '${audit['action']}'.replaceAll('.', ' '),
              style: const TextStyle(fontWeight: FontWeight.w800),
            ),
            subtitle: Text(
              '${audit['user']?['name'] ?? 'System'} · ${audit['created_at']}',
            ),
            trailing: audit['entity_type'] == null
                ? null
                : Text(
                    '#${audit['entity_id'] ?? '—'}',
                    style: const TextStyle(color: TablePlayColors.muted),
                  ),
          ),
        );
      }),
    ]);
  }

  Widget _adminSettings() {
    final settings = (data as Map)['settings'] as Map? ?? {};
    return _page([
      _adminHeading(
        'Restaurant configuration',
        'Shared by website and both apps',
        [
          FilledButton.icon(
            onPressed: () => _editSettings(settings),
            icon: const Icon(Icons.edit_rounded),
            label: const Text('Edit settings'),
          ),
        ],
      ),
      Card(
        child: Padding(
          padding: const EdgeInsets.all(18),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                '${settings['restaurant_name'] ?? 'TablePlay'}',
                style: Theme.of(
                  context,
                ).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w900),
              ),
              Text(
                '${settings['tagline'] ?? ''}',
                style: const TextStyle(color: TablePlayColors.muted),
              ),
              const Divider(height: 28),
              _settingLine(
                Icons.location_on_outlined,
                'Address',
                settings['address'],
              ),
              _settingLine(Icons.call_outlined, 'Phone', settings['phone']),
              _settingLine(
                Icons.mail_outline_rounded,
                'Email',
                settings['email'],
              ),
              _settingLine(
                Icons.receipt_long_outlined,
                'GSTIN',
                settings['gstin'],
              ),
              _settingLine(
                Icons.currency_rupee_rounded,
                'Tax',
                '${settings['tax_name']} · ${settings['tax_rate']}%',
              ),
              _settingLine(
                Icons.sports_esports_outlined,
                'Game time',
                '${settings['game_duration_minutes']} minutes',
              ),
              _settingLine(
                Icons.refresh_rounded,
                'Kitchen refresh',
                '${settings['kitchen_refresh_seconds']} seconds',
              ),
              _settingLine(
                Icons.devices_rounded,
                'Offline threshold',
                '${settings['device_offline_minutes']} minutes',
              ),
            ],
          ),
        ),
      ),
    ]);
  }

  Widget _adminAutomation() {
    final view = data as Map;
    final settings = view['settings'] as Map? ?? {};
    final scheduler = view['scheduler'] as Map? ?? {};
    final backup = view['backup'] as Map? ?? {};
    final alerts = view['alerts'] as Map? ?? {};
    final runs = view['recent_runs'] as List? ?? [];
    final schedulerLive = scheduler['running'] == true;
    return _page([
      _adminHeading(
        'Automation center',
        'Backups, timer cleanup, and early operational warnings',
        [
          OutlinedButton.icon(
            onPressed: () =>
                act('/admin/automation/run', success: 'Maintenance completed.'),
            icon: const Icon(Icons.play_arrow_rounded),
            label: const Text('Run maintenance'),
          ),
          FilledButton.icon(
            onPressed: backup['available'] == true
                ? () => act(
                    '/admin/automation/backup',
                    success: 'Verified database backup created.',
                  )
                : null,
            icon: const Icon(Icons.backup_rounded),
            label: const Text('Back up now'),
          ),
        ],
      ),
      Card(
        child: Padding(
          padding: const EdgeInsets.all(18),
          child: Row(
            children: [
              CircleAvatar(
                radius: 27,
                backgroundColor: schedulerLive
                    ? TablePlayColors.success.withValues(alpha: .12)
                    : TablePlayColors.warning.withValues(alpha: .12),
                child: Icon(
                  Icons.auto_awesome_motion_rounded,
                  color: schedulerLive
                      ? TablePlayColors.success
                      : TablePlayColors.warning,
                ),
              ),
              const SizedBox(width: 14),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      settings['automation_enabled'] == false
                          ? 'Automation is paused'
                          : schedulerLive
                          ? 'Windows scheduler is live'
                          : 'Waiting for scheduler check-in',
                      style: const TextStyle(
                        fontWeight: FontWeight.w900,
                        fontSize: 17,
                      ),
                    ),
                    Text(
                      scheduler['last_seen_at'] == null
                          ? 'No scheduled check has been recorded yet.'
                          : 'Last check ${scheduler['last_seen_at']}',
                      style: const TextStyle(color: TablePlayColors.muted),
                    ),
                  ],
                ),
              ),
              StatusPill(schedulerLive ? 'healthy' : 'warning'),
            ],
          ),
        ),
      ),
      const SectionTitle('Operational alerts'),
      _statsGrid([
        StatTile(
          label: 'Overdue timers',
          value: '${alerts['expired_game_sessions'] ?? 0}',
          icon: Icons.timer_off_outlined,
        ),
        StatTile(
          label: 'Delayed orders',
          value: '${alerts['delayed_orders'] ?? 0}',
          icon: Icons.receipt_long_rounded,
        ),
        StatTile(
          label: 'Delayed requests',
          value: '${alerts['delayed_requests'] ?? 0}',
          icon: Icons.notification_important_outlined,
        ),
        StatTile(
          label: 'Offline tablets',
          value: '${alerts['offline_devices'] ?? 0}',
          icon: Icons.tablet_android_rounded,
        ),
      ]),
      _adminHeading('Automation rules', 'Shared with the Windows service', [
        FilledButton.icon(
          onPressed: () => _editAutomation(settings),
          icon: const Icon(Icons.tune_rounded),
          label: const Text('Edit rules'),
        ),
      ]),
      Card(
        child: Padding(
          padding: const EdgeInsets.all(17),
          child: Column(
            children: [
              _settingLine(
                Icons.schedule_rounded,
                'Backup schedule',
                settings['auto_backup_enabled'] == true
                    ? 'Daily at ${settings['backup_time'] ?? '02:00'}'
                    : 'Disabled',
              ),
              _settingLine(
                Icons.inventory_2_outlined,
                'Retention',
                '${settings['backup_retention_days'] ?? 14} days',
              ),
              _settingLine(
                Icons.receipt_long_outlined,
                'Order alert',
                '${settings['pending_order_alert_minutes'] ?? 5} minutes',
              ),
              _settingLine(
                Icons.room_service_outlined,
                'Request alert',
                '${settings['service_request_alert_minutes'] ?? 3} minutes',
              ),
              _settingLine(
                Icons.backup_rounded,
                'Last backup',
                backup['last_backup_at'] ?? 'Not yet',
              ),
              _settingLine(
                Icons.storage_rounded,
                'Backup vault',
                '${backup['count'] ?? 0} files · ${_formatBytes(backup['total_bytes'])}',
              ),
            ],
          ),
        ),
      ),
      SectionTitle('Recent activity', subtitle: '${runs.length} entries'),
      if (runs.isEmpty)
        _empty('No automation history yet')
      else
        ...runs.map((value) {
          final run = value as Map;
          final runError = run['error'] == null ? '' : '\n${run['error']}';
          return Card(
            child: ListTile(
              leading: Icon(
                run['type'] == 'backup'
                    ? Icons.backup_rounded
                    : Icons.auto_awesome_motion_rounded,
                color: TablePlayColors.accent,
              ),
              title: Text(
                '${run['type']}'.replaceAll('_', ' '),
                style: const TextStyle(fontWeight: FontWeight.w900),
              ),
              subtitle: Text(
                '${run['user']?['name'] ?? 'Windows scheduler'} · ${run['started_at'] ?? ''}$runError',
              ),
              trailing: StatusPill('${run['status']}'),
            ),
          );
        }),
    ]);
  }

  Widget _adminSystem() {
    final health = (data as Map)['health'] as Map;
    final checks = health['checks'] as Map? ?? {};
    final runtime = health['runtime'] as Map? ?? {};
    final devices = health['devices'] as Map? ?? {};
    return _page([
      SectionTitle(
        'System status',
        subtitle: 'Checked ${health['checked_at']}',
      ),
      Card(
        child: ListTile(
          leading: Icon(
            Icons.monitor_heart_rounded,
            size: 36,
            color: health['overall'] == 'healthy'
                ? TablePlayColors.success
                : TablePlayColors.warning,
          ),
          title: Text(
            '${health['overall']}'.toUpperCase(),
            style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 18),
          ),
          subtitle: Text(
            '${devices['online']} of ${devices['active']} active tablets online',
          ),
          trailing: StatusPill('${health['overall']}'),
        ),
      ),
      const SectionTitle('Health checks'),
      ...checks.values.map((value) {
        final check = value as Map;
        return Card(
          child: ListTile(
            leading: Icon(
              check['status'] == 'healthy'
                  ? Icons.check_circle_rounded
                  : Icons.warning_rounded,
              color: check['status'] == 'healthy'
                  ? TablePlayColors.success
                  : TablePlayColors.warning,
            ),
            title: Text(
              '${check['label']}',
              style: const TextStyle(fontWeight: FontWeight.w900),
            ),
            subtitle: Text(
              '${check['message']}${check['error'] == null ? '' : '\n${check['error']}'}',
            ),
            trailing: StatusPill('${check['status']}'),
          ),
        );
      }),
      const SectionTitle('Runtime'),
      Card(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Wrap(
            spacing: 24,
            runSpacing: 14,
            children: runtime.entries
                .map(
                  (entry) => SizedBox(
                    width: 190,
                    child: Text(
                      '${entry.key.replaceAll('_', ' ')}\n${entry.value}',
                      style: const TextStyle(fontWeight: FontWeight.w700),
                    ),
                  ),
                )
                .toList(),
          ),
        ),
      ),
    ]);
  }

  Widget _adminHeading(String title, String subtitle, List<Widget> actions) =>
      Padding(
        padding: const EdgeInsets.only(top: 24, bottom: 10),
        child: Wrap(
          alignment: WrapAlignment.spaceBetween,
          crossAxisAlignment: WrapCrossAlignment.center,
          runSpacing: 8,
          children: [
            Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  title,
                  style: Theme.of(
                    context,
                  ).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w900),
                ),
                Text(
                  subtitle,
                  style: const TextStyle(
                    color: TablePlayColors.muted,
                    fontSize: 11,
                  ),
                ),
              ],
            ),
            Wrap(spacing: 8, runSpacing: 8, children: actions),
          ],
        ),
      );

  Widget _settingLine(IconData icon, String label, dynamic value) => Padding(
    padding: const EdgeInsets.only(bottom: 12),
    child: Row(
      children: [
        Icon(icon, color: TablePlayColors.accent, size: 20),
        const SizedBox(width: 11),
        SizedBox(
          width: 145,
          child: Text(
            label,
            style: const TextStyle(
              color: TablePlayColors.muted,
              fontWeight: FontWeight.w700,
            ),
          ),
        ),
        Expanded(
          child: Text(
            value == null || '$value'.isEmpty ? 'Not configured' : '$value',
            style: const TextStyle(fontWeight: FontWeight.w800),
          ),
        ),
      ],
    ),
  );

  double _asDouble(dynamic value) =>
      value is num ? value.toDouble() : double.tryParse('$value') ?? 0;

  String _formatBytes(dynamic value) {
    final bytes = value is num
        ? value.toDouble()
        : double.tryParse('$value') ?? 0;
    if (bytes < 1024) return '${bytes.toStringAsFixed(0)} B';
    if (bytes < 1024 * 1024) return '${(bytes / 1024).toStringAsFixed(1)} KB';
    return '${(bytes / (1024 * 1024)).toStringAsFixed(1)} MB';
  }

  // Kept temporarily for backwards-compatible response rendering while older
  // local servers are upgraded to the section endpoints.
  // ignore: unused_element
  Widget _adminLegacy() {
    final stats = data['stats'] as Map;
    final tables = data['tables'] as List,
        staff = data['staff'] as List,
        items = data['menu_items'] as List,
        games = data['games'] as List;
    final health = data['health'] as Map;
    return _page([
      if (sectionIndex == 0) ...[
        const SectionTitle('Today at a glance'),
        _statsGrid([
          StatTile(
            label: 'Sales today',
            value: '₹${stats['sales_today']}',
            icon: Icons.currency_rupee_rounded,
          ),
          StatTile(
            label: 'Orders today',
            value: '${stats['orders_today']}',
            icon: Icons.receipt_long_rounded,
          ),
          StatTile(
            label: 'Open tables',
            value: '${stats['open_tables']}',
            icon: Icons.table_restaurant_rounded,
          ),
          StatTile(
            label: 'Pending orders',
            value: '${stats['pending_orders']}',
            icon: Icons.notifications_active_rounded,
          ),
        ]),
      ],
      if (sectionIndex == 0 || sectionIndex == 1) ...[
        SectionTitle('Tables', subtitle: '${tables.length} configured'),
        ...tables.map((value) {
          final table = value as Map;
          return Card(
            child: ListTile(
              leading: CircleAvatar(child: Text('${table['table_code']}')),
              title: Text(
                '${table['table_name']}',
                style: const TextStyle(fontWeight: FontWeight.w800),
              ),
              subtitle: Text(
                '${table['capacity']} seats · ${table['sessions_count']} visits',
              ),
              trailing: StatusPill('${table['status']}'),
              onTap: () => _editTable(table),
            ),
          );
        }),
      ],
      if (sectionIndex == 0 || sectionIndex == 2) ...[
        SectionTitle(
          'Team',
          subtitle:
              '${staff.where((u) => u['is_active'] == true).length} active accounts',
        ),
        ...staff.map((value) {
          final user = value as Map;
          return Card(
            child: SwitchListTile(
              value: user['is_active'] == true,
              onChanged: user['id'] == widget.store.userId
                  ? null
                  : (_) async {
                      if (await confirm(
                        '${user['is_active'] == true ? 'Disable' : 'Enable'} ${user['name']}?',
                        'This changes access for the ${user['role']['display_name']} account.',
                        danger: user['is_active'] == true,
                      )) {
                        act(
                          '/admin/staff/${user['id']}/toggle',
                          success: 'Staff access updated.',
                        );
                      }
                    },
              title: Text(
                '${user['name']}',
                style: const TextStyle(fontWeight: FontWeight.w800),
              ),
              subtitle: Text(
                '${user['role']['display_name']} · @${user['username']}',
              ),
            ),
          );
        }),
      ],
      if (sectionIndex == 0 || sectionIndex == 3) ...[
        SectionTitle('Menu availability', subtitle: '${items.length} items'),
        ...items.map((value) {
          final item = value as Map;
          return Card(
            child: SwitchListTile(
              value: item['is_available'] == true,
              onChanged: (_) => act(
                '/admin/menu-items/${item['id']}/toggle',
                success: 'Menu availability updated.',
              ),
              title: Text(
                '${item['name']}',
                style: const TextStyle(fontWeight: FontWeight.w800),
              ),
              subtitle: Text(
                '${item['category']?['name'] ?? 'Menu'} · ₹${item['price']}',
              ),
            ),
          );
        }),
        SectionTitle('Games', subtitle: '${games.length} configured'),
        ...games.map((value) {
          final game = value as Map;
          return Card(
            child: SwitchListTile(
              value: game['is_active'] == true,
              onChanged: (_) => act(
                '/admin/games/${game['id']}/toggle',
                success: 'Game status updated.',
              ),
              title: Text(
                '${game['name']}',
                style: const TextStyle(fontWeight: FontWeight.w800),
              ),
              subtitle: Text('${game['player_mode']} player mode'),
            ),
          );
        }),
      ],
      if (sectionIndex == 0 || sectionIndex == 4) ...[
        const SectionTitle('System health'),
        Card(
          child: ListTile(
            leading: const Icon(
              Icons.monitor_heart_rounded,
              color: TablePlayColors.success,
            ),
            title: Text(
              '${health['overall']}'.toUpperCase(),
              style: const TextStyle(fontWeight: FontWeight.w900),
            ),
            subtitle: Text(
              '${health['devices']['online']} of ${health['devices']['active']} active tablets online',
            ),
            trailing: StatusPill('${health['overall']}'),
          ),
        ),
      ],
    ]);
  }

  Future<T?> _adminForm<T>(
    String title, {
    required List<Widget> Function(StateSetter setSheet) fields,
    required T Function() value,
    String submitLabel = 'Save',
  }) => showModalBottomSheet<T>(
    context: context,
    isScrollControlled: true,
    useSafeArea: true,
    builder: (sheetContext) => StatefulBuilder(
      builder: (_, setSheet) => Padding(
        padding: EdgeInsets.fromLTRB(
          20,
          18,
          20,
          MediaQuery.viewInsetsOf(sheetContext).bottom + 20,
        ),
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 620),
            child: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          title,
                          style: Theme.of(context).textTheme.titleLarge
                              ?.copyWith(fontWeight: FontWeight.w900),
                        ),
                      ),
                      IconButton(
                        onPressed: () => Navigator.pop(sheetContext),
                        icon: const Icon(Icons.close_rounded),
                      ),
                    ],
                  ),
                  const SizedBox(height: 14),
                  ...fields(setSheet),
                  const SizedBox(height: 18),
                  Row(
                    children: [
                      Expanded(
                        child: OutlinedButton(
                          onPressed: () => Navigator.pop(sheetContext),
                          child: const Text('Cancel'),
                        ),
                      ),
                      const SizedBox(width: 10),
                      Expanded(
                        child: FilledButton(
                          onPressed: () => Navigator.pop(sheetContext, value()),
                          child: Text(submitLabel),
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
  );

  Widget _gap() => const SizedBox(height: 12);

  Future<void> _delete(String path, String success) async {
    try {
      await api.delete(path);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(success),
            backgroundColor: TablePlayColors.success,
          ),
        );
      }
      await load(silent: true, force: true);
    } catch (exception) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(exception.toString()),
            backgroundColor: TablePlayColors.danger,
          ),
        );
      }
    }
  }

  Future<void> _addTable() async {
    final code = TextEditingController(),
        name = TextEditingController(),
        capacity = TextEditingController(text: '4');
    final result = await _adminForm<Map<String, dynamic>>(
      'Add dining table',
      fields: (_) => [
        TextField(
          controller: code,
          textCapitalization: TextCapitalization.characters,
          decoration: const InputDecoration(
            labelText: 'Table code',
            hintText: 'T11',
          ),
        ),
        _gap(),
        TextField(
          controller: name,
          decoration: const InputDecoration(
            labelText: 'Display name',
            hintText: 'Garden Table 11',
          ),
        ),
        _gap(),
        TextField(
          controller: capacity,
          keyboardType: TextInputType.number,
          decoration: const InputDecoration(labelText: 'Seats'),
        ),
      ],
      value: () => {
        'table_code': code.text.trim().toUpperCase(),
        'table_name': name.text.trim(),
        'capacity': int.tryParse(capacity.text) ?? 4,
      },
      submitLabel: 'Create table',
    );
    code.dispose();
    name.dispose();
    capacity.dispose();
    if (result != null) {
      await act('/admin/tables', body: result, success: 'Table created.');
    }
  }

  Future<void> _pairDevice(List devices, List tables) async {
    final pairingControl = PairingControlState.evaluate(
      entitlements: entitlements,
      devices: devices,
      tables: tables,
    );
    if (!pairingControl.canPair) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(pairingControl.message)));
      return;
    }
    devices = pairingControl.eligibleDevices;
    tables = pairingControl.eligibleTables;
    int deviceId = (devices.first['id'] as num).toInt();
    int tableId = (tables.first['id'] as num).toInt();
    final result = await _adminForm<Map<String, dynamic>>(
      'Pair customer tablet',
      fields: (setSheet) => [
        DropdownButtonFormField<int>(
          initialValue: deviceId,
          decoration: const InputDecoration(labelText: 'Tablet'),
          items: devices
              .map(
                (value) => DropdownMenuItem<int>(
                  value: (value['id'] as num).toInt(),
                  child: Text('${value['device_name']}'),
                ),
              )
              .toList(),
          onChanged: (value) => setSheet(() => deviceId = value!),
        ),
        _gap(),
        DropdownButtonFormField<int>(
          initialValue: tableId,
          decoration: const InputDecoration(labelText: 'Dining table'),
          items: tables
              .map(
                (value) => DropdownMenuItem<int>(
                  value: (value['id'] as num).toInt(),
                  child: Text(
                    '${value['table_code']} · ${value['table_name']}',
                  ),
                ),
              )
              .toList(),
          onChanged: (value) => setSheet(() => tableId = value!),
        ),
      ],
      value: () => {'device_id': deviceId, 'dining_table_id': tableId},
      submitLabel: 'Pair tablet',
    );
    if (result != null) {
      await act('/admin/pairings', body: result, success: 'Tablet paired.');
    }
  }

  Future<void> _addStaff(List roles) async {
    if (roles.isEmpty) return;
    final name = TextEditingController(),
        username = TextEditingController(),
        email = TextEditingController(),
        mobile = TextEditingController(),
        password = TextEditingController(),
        pin = TextEditingController();
    int roleId = (roles.first['id'] as num).toInt();
    bool showPassword = false, showPin = false;
    final result = await _adminForm<Map<String, dynamic>>(
      'Create staff account',
      fields: (setSheet) => [
        DropdownButtonFormField<int>(
          initialValue: roleId,
          decoration: const InputDecoration(labelText: 'Role'),
          items: roles
              .map(
                (value) => DropdownMenuItem<int>(
                  value: (value['id'] as num).toInt(),
                  child: Text('${value['display_name']}'),
                ),
              )
              .toList(),
          onChanged: (value) => setSheet(() => roleId = value!),
        ),
        _gap(),
        TextField(
          controller: name,
          decoration: const InputDecoration(labelText: 'Full name'),
        ),
        _gap(),
        TextField(
          controller: username,
          decoration: const InputDecoration(labelText: 'Username'),
        ),
        _gap(),
        TextField(
          controller: email,
          keyboardType: TextInputType.emailAddress,
          decoration: const InputDecoration(labelText: 'Email (optional)'),
        ),
        _gap(),
        TextField(
          controller: mobile,
          keyboardType: TextInputType.phone,
          decoration: const InputDecoration(labelText: 'Mobile (optional)'),
        ),
        _gap(),
        TextField(
          controller: password,
          obscureText: !showPassword,
          inputFormatters: [LengthLimitingTextInputFormatter(255)],
          decoration: InputDecoration(
            labelText: 'Password',
            helperText: 'At least 8 characters',
            suffixIcon: IconButton(
              tooltip: showPassword ? 'Hide password' : 'Show password',
              onPressed: () =>
                  setSheet(() => showPassword = !showPassword),
              icon: Icon(showPassword
                  ? Icons.visibility_off_outlined
                  : Icons.visibility_outlined),
            ),
          ),
        ),
        _gap(),
        TextField(
          controller: pin,
          obscureText: !showPin,
          keyboardType: TextInputType.number,
          inputFormatters: [
            FilteringTextInputFormatter.digitsOnly,
            LengthLimitingTextInputFormatter(12),
          ],
          decoration: InputDecoration(
            labelText: 'PIN (optional)',
            helperText: '4 to 12 digits',
            suffixIcon: IconButton(
              tooltip: showPin ? 'Hide PIN' : 'Show PIN',
              onPressed: () => setSheet(() => showPin = !showPin),
              icon: Icon(showPin
                  ? Icons.visibility_off_outlined
                  : Icons.visibility_outlined),
            ),
          ),
        ),
      ],
      value: () => {
        'role_id': roleId,
        'name': name.text.trim(),
        'username': username.text.trim(),
        'email': email.text.trim(),
        'mobile': mobile.text.trim(),
        'password': password.text,
        'pin': pin.text.trim(),
      },
      submitLabel: 'Create account',
    );
    for (final controller in [name, username, email, mobile, password, pin]) {
      controller.dispose();
    }
    if (result != null) {
      await act(
        '/admin/staff',
        body: result,
        success: 'Staff account created.',
      );
    }
  }

  Future<void> _addCategory() async {
    final name = TextEditingController(),
        description = TextEditingController(),
        order = TextEditingController(text: '0');
    final result = await _adminForm<Map<String, dynamic>>(
      'Add menu category',
      fields: (_) => [
        TextField(
          controller: name,
          decoration: const InputDecoration(labelText: 'Category name'),
        ),
        _gap(),
        TextField(
          controller: description,
          maxLines: 2,
          decoration: const InputDecoration(
            labelText: 'Description (optional)',
          ),
        ),
        _gap(),
        TextField(
          controller: order,
          keyboardType: TextInputType.number,
          decoration: const InputDecoration(labelText: 'Sort order'),
        ),
      ],
      value: () => {
        'name': name.text.trim(),
        'description': description.text.trim(),
        'sort_order': int.tryParse(order.text) ?? 0,
      },
      submitLabel: 'Add category',
    );
    name.dispose();
    description.dispose();
    order.dispose();
    if (result != null) {
      await act(
        '/admin/categories',
        body: result,
        success: 'Category created.',
      );
    }
  }

  Future<void> _addMenuItem(List categories) async {
    if (categories.isEmpty) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(const SnackBar(content: Text('Create a category first.')));
      return;
    }
    final name = TextEditingController(),
        description = TextEditingController(),
        price = TextEditingController(),
        minutes = TextEditingController(text: '15');
    int categoryId = (categories.first['id'] as num).toInt();
    String foodType = 'veg';
    final result = await _adminForm<Map<String, dynamic>>(
      'Add menu item',
      fields: (setSheet) => [
        DropdownButtonFormField<int>(
          initialValue: categoryId,
          decoration: const InputDecoration(labelText: 'Category'),
          items: categories
              .map(
                (value) => DropdownMenuItem<int>(
                  value: (value['id'] as num).toInt(),
                  child: Text('${value['name']}'),
                ),
              )
              .toList(),
          onChanged: (value) => setSheet(() => categoryId = value!),
        ),
        _gap(),
        TextField(
          controller: name,
          decoration: const InputDecoration(labelText: 'Item name'),
        ),
        _gap(),
        TextField(
          controller: description,
          maxLines: 2,
          decoration: const InputDecoration(
            labelText: 'Description (optional)',
          ),
        ),
        _gap(),
        TextField(
          controller: price,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          decoration: const InputDecoration(labelText: 'Price'),
        ),
        _gap(),
        DropdownButtonFormField<String>(
          initialValue: foodType,
          decoration: const InputDecoration(labelText: 'Food type'),
          items: ['veg', 'non_veg', 'egg', 'other']
              .map(
                (value) => DropdownMenuItem(
                  value: value,
                  child: Text(value.replaceAll('_', ' ')),
                ),
              )
              .toList(),
          onChanged: (value) => setSheet(() => foodType = value!),
        ),
        _gap(),
        TextField(
          controller: minutes,
          keyboardType: TextInputType.number,
          decoration: const InputDecoration(labelText: 'Preparation minutes'),
        ),
      ],
      value: () => {
        'category_id': categoryId,
        'name': name.text.trim(),
        'short_description': description.text.trim(),
        'description': description.text.trim(),
        'price': double.tryParse(price.text) ?? 0,
        'food_type': foodType,
        'spice_level': 'none',
        'preparation_minutes': int.tryParse(minutes.text),
      },
      submitLabel: 'Add menu item',
    );
    for (final controller in [name, description, price, minutes]) {
      controller.dispose();
    }
    if (result != null) {
      await act(
        '/admin/menu-items',
        body: result,
        success: 'Menu item created.',
      );
    }
  }

  Future<void> _editMenuItem(Map item, List categories) async {
    final name = TextEditingController(text: '${item['name'] ?? ''}');
    final description = TextEditingController(
      text: '${item['description'] ?? ''}',
    );
    final ingredients = TextEditingController(
      text: '${item['ingredients'] ?? ''}',
    );
    final allergens = TextEditingController(
      text: (item['allergens'] as List? ?? []).join(', '),
    );
    final price = TextEditingController(text: '${item['price'] ?? ''}');
    final offer = TextEditingController(
      text: '${item['discount_price'] ?? ''}',
    );
    final minutes = TextEditingController(
      text: '${item['preparation_minutes'] ?? 15}',
    );
    int categoryId = (item['category_id'] as num).toInt();
    String foodType = '${item['food_type'] ?? 'veg'}';
    String spice = '${item['spice_level'] ?? 'none'}';
    final result = await _adminForm<Map<String, dynamic>>(
      'Edit menu item',
      fields: (setSheet) => [
        DropdownButtonFormField<int>(
          initialValue: categoryId,
          decoration: const InputDecoration(labelText: 'Category'),
          items: categories
              .map(
                (value) => DropdownMenuItem<int>(
                  value: (value['id'] as num).toInt(),
                  child: Text('${value['name']}'),
                ),
              )
              .toList(),
          onChanged: (value) => setSheet(() => categoryId = value!),
        ),
        _gap(),
        TextField(
          controller: name,
          decoration: const InputDecoration(labelText: 'Item name'),
        ),
        _gap(),
        TextField(
          controller: description,
          maxLines: 3,
          decoration: const InputDecoration(labelText: 'Customer description'),
        ),
        _gap(),
        TextField(
          controller: ingredients,
          decoration: const InputDecoration(labelText: 'Ingredients'),
        ),
        _gap(),
        TextField(
          controller: allergens,
          decoration: const InputDecoration(
            labelText: 'Allergens (comma separated)',
          ),
        ),
        _gap(),
        TextField(
          controller: price,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          decoration: const InputDecoration(labelText: 'Regular price'),
        ),
        _gap(),
        TextField(
          controller: offer,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          decoration: const InputDecoration(
            labelText: 'Offer price (optional)',
          ),
        ),
        _gap(),
        DropdownButtonFormField<String>(
          initialValue: foodType,
          decoration: const InputDecoration(labelText: 'Food type'),
          items: ['veg', 'non_veg', 'egg', 'other']
              .map(
                (v) => DropdownMenuItem(
                  value: v,
                  child: Text(v.replaceAll('_', ' ')),
                ),
              )
              .toList(),
          onChanged: (value) => setSheet(() => foodType = value!),
        ),
        _gap(),
        DropdownButtonFormField<String>(
          initialValue: spice,
          decoration: const InputDecoration(labelText: 'Spice level'),
          items: [
            'none',
            'mild',
            'medium',
            'hot',
          ].map((v) => DropdownMenuItem(value: v, child: Text(v))).toList(),
          onChanged: (value) => setSheet(() => spice = value!),
        ),
        _gap(),
        TextField(
          controller: minutes,
          keyboardType: TextInputType.number,
          decoration: const InputDecoration(labelText: 'Preparation minutes'),
        ),
      ],
      value: () => {
        ...Map<String, dynamic>.from(item),
        'category_id': categoryId,
        'name': name.text.trim(),
        'short_description': description.text.trim(),
        'description': description.text.trim(),
        'ingredients': ingredients.text.trim(),
        'allergens': allergens.text
            .split(',')
            .map((v) => v.trim())
            .where((v) => v.isNotEmpty)
            .toList(),
        'image_path': item['image_path'],
        'price': double.tryParse(price.text) ?? 0,
        'discount_price': double.tryParse(offer.text),
        'food_type': foodType,
        'spice_level': spice,
        'preparation_minutes': int.tryParse(minutes.text),
      },
      submitLabel: 'Save changes',
    );
    for (final controller in [
      name,
      description,
      ingredients,
      allergens,
      price,
      offer,
      minutes,
    ]) {
      controller.dispose();
    }
    if (result != null) {
      await put(
        '/admin/menu-items/${item['id']}',
        result,
        'Menu item updated.',
      );
    }
  }

  Future<void> _uploadMenuPhoto(Map item) async {
    try {
      final selection = await FilePicker.platform.pickFiles(
        type: FileType.image,
        withData: true,
        allowMultiple: false,
      );
      if (selection == null) return;
      final file = selection.files.single;
      if (file.bytes == null) {
        throw Exception('Could not read the selected photo.');
      }
      if (file.size > 5 * 1024 * 1024) {
        throw Exception('Choose a photo smaller than 5 MB.');
      }
      await api.uploadFile(
        '/admin/menu-items/${item['id']}/image',
        field: 'image',
        bytes: file.bytes!,
        filename: file.name,
      );
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Menu photo updated on every tablet.'),
            backgroundColor: TablePlayColors.success,
          ),
        );
      }
      await load(silent: true, force: true);
    } catch (exception) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(exception.toString()),
            backgroundColor: TablePlayColors.danger,
          ),
        );
      }
    }
  }

  Future<void> _addGame() async {
    final name = TextEditingController(),
        slug = TextEditingController(),
        description = TextEditingController(),
        path = TextEditingController(text: '/games/'),
        order = TextEditingController(text: '10');
    String mode = 'one';
    final result = await _adminForm<Map<String, dynamic>>(
      'Add game',
      fields: (setSheet) => [
        TextField(
          controller: name,
          decoration: const InputDecoration(labelText: 'Game name'),
        ),
        _gap(),
        TextField(
          controller: slug,
          decoration: const InputDecoration(
            labelText: 'Slug',
            hintText: 'memory-match',
          ),
        ),
        _gap(),
        TextField(
          controller: description,
          maxLines: 2,
          decoration: const InputDecoration(
            labelText: 'Description (optional)',
          ),
        ),
        _gap(),
        DropdownButtonFormField<String>(
          initialValue: mode,
          decoration: const InputDecoration(labelText: 'Player mode'),
          items: ['one', 'two', 'four']
              .map(
                (value) => DropdownMenuItem(
                  value: value,
                  child: Text('$value player'),
                ),
              )
              .toList(),
          onChanged: (value) => setSheet(() => mode = value!),
        ),
        _gap(),
        TextField(
          controller: path,
          decoration: const InputDecoration(labelText: 'Game path'),
        ),
        _gap(),
        TextField(
          controller: order,
          keyboardType: TextInputType.number,
          decoration: const InputDecoration(labelText: 'Sort order'),
        ),
      ],
      value: () => {
        'name': name.text.trim(),
        'slug': slug.text.trim(),
        'description': description.text.trim(),
        'player_mode': mode,
        'game_path': path.text.trim(),
        'sort_order': int.tryParse(order.text) ?? 10,
      },
      submitLabel: 'Add game',
    );
    for (final controller in [name, slug, description, path, order]) {
      controller.dispose();
    }
    if (result != null) {
      await act('/admin/games', body: result, success: 'Game added.');
    }
  }

  Future<void> _editSettings(Map settings) async {
    String text(String key, [String fallback = '']) =>
        settings[key] == null ? fallback : '${settings[key]}';
    final controllers = <String, TextEditingController>{
      for (final entry in <String, String>{
        'restaurant_name': 'TablePlay Restaurant',
        'tagline': '',
        'address': '',
        'phone': '',
        'email': '',
        'gstin': '',
        'brand_color': '#ef6a3a',
        'timezone': 'Asia/Kolkata',
        'currency': 'INR',
        'tax_name': 'GST',
        'tax_rate': '5',
        'game_duration_minutes': '60',
        'kitchen_refresh_seconds': '4',
        'device_offline_minutes': '5',
        'receipt_footer': '',
      }.entries)
        entry.key: TextEditingController(text: text(entry.key, entry.value)),
    };
    TextField field(
      String key,
      String label, {
      TextInputType? keyboard,
      int maxLines = 1,
    }) => TextField(
      controller: controllers[key],
      keyboardType: keyboard,
      maxLines: maxLines,
      decoration: InputDecoration(labelText: label),
    );
    final result = await _adminForm<Map<String, dynamic>>(
      'Restaurant settings',
      fields: (_) => [
        field('restaurant_name', 'Restaurant name'),
        _gap(),
        field('tagline', 'Tagline'),
        _gap(),
        field('address', 'Address', maxLines: 2),
        _gap(),
        field('phone', 'Phone', keyboard: TextInputType.phone),
        _gap(),
        field('email', 'Email', keyboard: TextInputType.emailAddress),
        _gap(),
        field('gstin', 'GSTIN'),
        _gap(),
        field('brand_color', 'Brand colour (#RRGGBB)'),
        _gap(),
        field('timezone', 'Timezone'),
        _gap(),
        field('currency', 'Currency code'),
        _gap(),
        field('tax_name', 'Tax name'),
        _gap(),
        field(
          'tax_rate',
          'Tax rate (%)',
          keyboard: const TextInputType.numberWithOptions(decimal: true),
        ),
        _gap(),
        field(
          'game_duration_minutes',
          'Game duration (minutes)',
          keyboard: TextInputType.number,
        ),
        _gap(),
        field(
          'kitchen_refresh_seconds',
          'Kitchen refresh (seconds)',
          keyboard: TextInputType.number,
        ),
        _gap(),
        field(
          'device_offline_minutes',
          'Tablet offline threshold (minutes)',
          keyboard: TextInputType.number,
        ),
        _gap(),
        field('receipt_footer', 'Receipt footer', maxLines: 2),
      ],
      value: () => {
        'restaurant_name': controllers['restaurant_name']!.text.trim(),
        'tagline': controllers['tagline']!.text.trim(),
        'address': controllers['address']!.text.trim(),
        'phone': controllers['phone']!.text.trim(),
        'email': controllers['email']!.text.trim(),
        'gstin': controllers['gstin']!.text.trim(),
        'brand_color': controllers['brand_color']!.text.trim(),
        'timezone': controllers['timezone']!.text.trim(),
        'currency': controllers['currency']!.text.trim().toUpperCase(),
        'tax_name': controllers['tax_name']!.text.trim(),
        'tax_rate': double.tryParse(controllers['tax_rate']!.text) ?? 0,
        'game_duration_minutes':
            int.tryParse(controllers['game_duration_minutes']!.text) ?? 60,
        'kitchen_refresh_seconds':
            int.tryParse(controllers['kitchen_refresh_seconds']!.text) ?? 4,
        'device_offline_minutes':
            int.tryParse(controllers['device_offline_minutes']!.text) ?? 5,
        'receipt_footer': controllers['receipt_footer']!.text.trim(),
      },
      submitLabel: 'Save settings',
    );
    for (final controller in controllers.values) {
      controller.dispose();
    }
    if (result != null) {
      await put('/admin/settings', result, 'Restaurant settings saved.');
    }
  }

  Future<void> _editAutomation(Map settings) async {
    String text(String key, String fallback) =>
        settings[key] == null ? fallback : '${settings[key]}';
    final backupTime = TextEditingController(
      text: text('backup_time', '02:00'),
    );
    final retention = TextEditingController(
      text: text('backup_retention_days', '14'),
    );
    final orderAlert = TextEditingController(
      text: text('pending_order_alert_minutes', '5'),
    );
    final requestAlert = TextEditingController(
      text: text('service_request_alert_minutes', '3'),
    );
    var enabled = settings['automation_enabled'] != false;
    var backupEnabled = settings['auto_backup_enabled'] != false;
    final result = await _adminForm<Map<String, dynamic>>(
      'Automation rules',
      fields: (setSheet) => [
        SwitchListTile(
          contentPadding: EdgeInsets.zero,
          value: enabled,
          onChanged: (value) => setSheet(() => enabled = value),
          title: const Text('Scheduled maintenance'),
          subtitle: const Text('Check timers and delays every minute'),
        ),
        SwitchListTile(
          contentPadding: EdgeInsets.zero,
          value: backupEnabled,
          onChanged: (value) => setSheet(() => backupEnabled = value),
          title: const Text('Daily database backup'),
          subtitle: const Text('Stored privately on the local server'),
        ),
        TextField(
          controller: backupTime,
          decoration: const InputDecoration(
            labelText: 'Backup time (24-hour HH:mm)',
            hintText: '02:00',
          ),
        ),
        _gap(),
        TextField(
          controller: retention,
          keyboardType: TextInputType.number,
          decoration: const InputDecoration(labelText: 'Retention (days)'),
        ),
        _gap(),
        TextField(
          controller: orderAlert,
          keyboardType: TextInputType.number,
          decoration: const InputDecoration(
            labelText: 'Pending-order alert (minutes)',
          ),
        ),
        _gap(),
        TextField(
          controller: requestAlert,
          keyboardType: TextInputType.number,
          decoration: const InputDecoration(
            labelText: 'Guest-request alert (minutes)',
          ),
        ),
      ],
      value: () => {
        'automation_enabled': enabled,
        'auto_backup_enabled': backupEnabled,
        'backup_time': backupTime.text.trim(),
        'backup_retention_days': int.tryParse(retention.text) ?? 14,
        'pending_order_alert_minutes': int.tryParse(orderAlert.text) ?? 5,
        'service_request_alert_minutes': int.tryParse(requestAlert.text) ?? 3,
      },
      submitLabel: 'Save automation',
    );
    for (final controller in [
      backupTime,
      retention,
      orderAlert,
      requestAlert,
    ]) {
      controller.dispose();
    }
    if (result != null) {
      await put('/admin/automation', result, 'Automation settings saved.');
    }
  }

  Future<void> _editTable(Map table) async {
    final name = TextEditingController(text: '${table['table_name']}');
    final capacity = TextEditingController(text: '${table['capacity']}');
    var status = '${table['status']}', active = table['is_active'] == true;
    final result = await showDialog<Map<String, dynamic>>(
      context: context,
      builder: (dialogContext) => StatefulBuilder(
        builder: (_, setDialog) => AlertDialog(
          title: Text('Manage ${table['table_code']}'),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                TextField(
                  controller: name,
                  decoration: const InputDecoration(labelText: 'Display name'),
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: capacity,
                  keyboardType: TextInputType.number,
                  decoration: const InputDecoration(labelText: 'Seats'),
                ),
                const SizedBox(height: 12),
                DropdownButtonFormField(
                  initialValue: status,
                  decoration: const InputDecoration(labelText: 'Status'),
                  items:
                      [
                            'available',
                            'occupied',
                            'reserved',
                            'cleaning',
                            'disabled',
                          ]
                          .map(
                            (value) => DropdownMenuItem(
                              value: value,
                              child: Text(value),
                            ),
                          )
                          .toList(),
                  onChanged: (value) => setDialog(() => status = value!),
                ),
                SwitchListTile(
                  contentPadding: EdgeInsets.zero,
                  value: active,
                  onChanged: (value) => setDialog(() => active = value),
                  title: const Text('Active'),
                ),
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(dialogContext),
              child: const Text('Cancel'),
            ),
            FilledButton(
              onPressed: () => Navigator.pop(dialogContext, {
                'table_name': name.text.trim(),
                'capacity': int.tryParse(capacity.text) ?? 4,
                'status': status,
                'is_active': active,
              }),
              child: const Text('Save'),
            ),
          ],
        ),
      ),
    );
    name.dispose();
    capacity.dispose();
    if (result != null) {
      await put('/admin/tables/${table['id']}', result, 'Table updated.');
    }
  }

  Widget _counter() {
    final orders = data['orders'] as List,
        sessions = data['sessions'] as List,
        requests = data['service_requests'] as List,
        games = data['game_sessions'] as List;
    return _page([
      if (sectionIndex == 0 || sectionIndex == 1) ...[
        SectionTitle(
          'Incoming orders',
          subtitle:
              '${orders.where((o) => o['status'] == 'pending').length} need confirmation',
        ),
        if (orders.isEmpty)
          _empty('Order queue is clear')
        else
          ...orders.map((value) => _order(value as Map, counter: true)),
      ],
      if (sectionIndex == 0 || sectionIndex == 2) ...[
        SectionTitle('Guest requests', subtitle: '${requests.length} open'),
        if (requests.isEmpty)
          _empty('All guests attended')
        else
          ...requests.map((value) {
            final request = value as Map;
            return Card(
              child: ListTile(
                leading: const Icon(Icons.room_service_rounded),
                title: Text(
                  '${tableCode(request)} · ${request['request_type']}'
                      .replaceAll('_', ' '),
                  style: const TextStyle(fontWeight: FontWeight.w800),
                ),
                subtitle: Text('${request['status']}'),
                trailing: Wrap(
                  spacing: 5,
                  children: [
                    if (request['status'] == 'pending')
                      IconButton(
                        onPressed: () => act(
                          '/counter/service-requests/${request['id']}',
                          body: {'status': 'acknowledged'},
                          success: 'Request acknowledged.',
                        ),
                        icon: const Icon(Icons.visibility_rounded),
                      ),
                    IconButton(
                      onPressed: () => act(
                        '/counter/service-requests/${request['id']}',
                        body: {'status': 'completed'},
                        success: 'Request completed.',
                      ),
                      icon: const Icon(
                        Icons.check_circle_rounded,
                        color: TablePlayColors.success,
                      ),
                    ),
                  ],
                ),
              ),
            );
          }),
      ],
      if (sectionIndex == 0 || sectionIndex == 3) ...[
        SectionTitle('Billing', subtitle: '${sessions.length} open tables'),
        if (sessions.isEmpty) _empty('No open tables to bill'),
        ...sessions.map((value) {
          final session = value as Map, bill = session['bill'];
          return Card(
            child: ListTile(
              title: Text(
                '${session['dining_table']['table_code']} · ${session['session_code']}',
                style: const TextStyle(fontWeight: FontWeight.w800),
              ),
              subtitle: Text(
                '${(session['orders'] as List).length} orders · ${session['status']}',
              ),
              trailing: bill == null
                  ? FilledButton(
                      onPressed: () async {
                        final discount = await ask(
                          'Generate bill',
                          'Discount amount',
                          initial: '0',
                          keyboard: TextInputType.number,
                        );
                        if (discount != null) {
                          act(
                            '/counter/bills',
                            body: {
                              'table_session_id': session['id'],
                              'discount_amount': double.tryParse(discount) ?? 0,
                            },
                            success: 'Bill generated.',
                          );
                        }
                      },
                      child: const Text('Bill'),
                    )
                  : bill['payment_status'] == 'unpaid'
                  ? FilledButton(
                      onPressed: () async {
                        final amount = await ask(
                          'Record cash',
                          'Amount received',
                          initial: '${bill['grand_total']}',
                          keyboard: TextInputType.number,
                        );
                        if (amount != null) {
                          act(
                            '/counter/bills/${bill['id']}/cash-payment',
                            body: {
                              'received_amount': double.tryParse(amount) ?? 0,
                            },
                            success: 'Cash payment recorded.',
                          );
                        }
                      },
                      child: const Text('Pay'),
                    )
                  : const StatusPill('paid'),
            ),
          );
        }),
      ],
      if (sectionIndex == 0 || sectionIndex == 4) ...[
        SectionTitle('Game timers', subtitle: '${games.length} active'),
        if (games.isEmpty) _empty('No active game timers'),
        ...games.map((value) {
          final game = Map<String, dynamic>.from(value as Map);
          return _GameTimerCard(
            game: game,
            onExtend: (minutes) => act(
              '/counter/game-sessions/${game['id']}/extend',
              body: {'minutes': minutes},
              success: 'Game time extended by $minutes minutes.',
            ),
            onCustom: () async {
              final minutes = await ask(
                'Extend game time',
                'Minutes (1–240)',
                initial: '15',
                keyboard: TextInputType.number,
              );
              if (minutes == null) return;
              final parsed = int.tryParse(minutes);
              if (parsed == null || parsed < 1 || parsed > 240) {
                if (mounted) {
                  ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(
                      content: Text('Enter a value from 1 to 240 minutes.'),
                      backgroundColor: TablePlayColors.danger,
                    ),
                  );
                }
                return;
              }
              await act(
                '/counter/game-sessions/${game['id']}/extend',
                body: {'minutes': parsed},
                success: 'Game time extended by $parsed minutes.',
              );
            },
            onStop: () async {
              if (await confirm(
                'Stop games?',
                'Games will lock immediately for this table.',
                action: 'Stop games',
                danger: true,
              )) {
                await act(
                  '/counter/game-sessions/${game['id']}/stop',
                  success: 'Games stopped.',
                );
              }
            },
          );
        }),
      ],
    ]);
  }

  Widget _kitchen() {
    final orders = data as List;
    final status = switch (sectionIndex) {
      1 => 'confirmed',
      2 => 'preparing',
      3 => 'ready',
      _ => null,
    };
    final visibleOrders = status == null
        ? orders
        : orders.where((order) => order['status'] == status).toList();
    return _page([
      SectionTitle(
        destinations[sectionIndex].label,
        subtitle:
            '${visibleOrders.length} ticket${visibleOrders.length == 1 ? '' : 's'} · sound alerts enabled',
      ),
      if (visibleOrders.isEmpty)
        _empty('No ${destinations[sectionIndex].label.toLowerCase()}')
      else
        ...visibleOrders.map((value) => _order(value as Map, kitchen: true)),
    ]);
  }

  Widget _waiter() {
    final requests = data['requests'] as List,
        ready = data['ready_orders'] as List,
        tables = data['tables'] as List,
        menu = data['menu'] as List? ?? [];
    final waiterOrdering = _featureAllowed('waiter_ordering');
    return _page([
      if (sectionIndex == 0 || sectionIndex == 1) ...[
        SectionTitle('Guest requests', subtitle: '${requests.length} waiting'),
        if (requests.isEmpty)
          _empty('No guest requests')
        else
          ...requests.map((value) {
            final request = value as Map;
            return Card(
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Expanded(
                          child: Text(
                            '${tableCode(request)} · ${request['request_type']}'
                                .replaceAll('_', ' '),
                            style: const TextStyle(fontWeight: FontWeight.w900),
                          ),
                        ),
                        StatusPill('${request['status']}'),
                      ],
                    ),
                    if (request['message'] != null)
                      Padding(
                        padding: const EdgeInsets.only(top: 6),
                        child: Text(
                          '${request['message']}',
                          style: const TextStyle(color: TablePlayColors.muted),
                        ),
                      ),
                    const SizedBox(height: 10),
                    Row(
                      children: [
                        if (request['status'] == 'pending')
                          Expanded(
                            child: OutlinedButton(
                              onPressed: () => act(
                                '/waiter/requests/${request['id']}/acknowledge',
                                success: 'Request accepted.',
                              ),
                              child: const Text('Acknowledge'),
                            ),
                          ),
                        if (request['status'] == 'pending')
                          const SizedBox(width: 8),
                        Expanded(
                          child: FilledButton(
                            onPressed: () => act(
                              '/waiter/requests/${request['id']}/complete',
                              success: 'Request completed.',
                            ),
                            child: const Text('Complete'),
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            );
          }),
      ],
      if (sectionIndex == 0 || sectionIndex == 2) ...[
        SectionTitle('Ready for service', subtitle: '${ready.length} orders'),
        if (ready.isEmpty)
          _empty('No orders waiting')
        else
          ...ready.map((value) => _order(value as Map, waiter: true)),
      ],
      if (sectionIndex == 0 || sectionIndex == 3) ...[
        SectionTitle(
          'My floor',
          subtitle: waiterOrdering
              ? '${tables.length} active tables'
              : '${tables.length} tables · ordering is view only',
        ),
        if (!waiterOrdering)
          Card(
            color: TablePlayColors.gold.withValues(alpha: .10),
            child: const ListTile(
              leading: Icon(
                Icons.lock_outline_rounded,
                color: TablePlayColors.accent,
              ),
              title: Text(
                'Waiter ordering is not included',
                style: TextStyle(fontWeight: FontWeight.w900),
              ),
              subtitle: Text(
                'The waiter can still handle requests, serve ready orders, and request bills. Upgrade the plan to open visits and take orders.',
              ),
            ),
          ),
        if (tables.isEmpty) _empty('No tables assigned'),
        ...tables.map((value) {
          final table = value as Map, sessions = table['sessions'] as List;
          final session = sessions.isEmpty ? null : sessions.first;
          return Card(
            child: ListTile(
              leading: CircleAvatar(child: Text('${table['table_code']}')),
              title: Text(
                '${table['table_name']}',
                style: const TextStyle(fontWeight: FontWeight.w900),
              ),
              subtitle: Text(
                session == null
                    ? '${table['capacity']} seats · ${table['status']}'
                    : '${session['guest_count']} guests · ${session['status']}',
              ),
              trailing: session == null
                  ? IconButton(
                      tooltip: waiterOrdering
                          ? 'Open table visit'
                          : 'Waiter ordering is not included in this plan',
                      onPressed: waiterOrdering
                          ? () async {
                              final guests = await ask(
                                'Open ${table['table_code']}',
                                'Guest count',
                                initial: '2',
                                keyboard: TextInputType.number,
                              );
                              if (guests != null) {
                                act(
                                  '/waiter/tables/${table['id']}/sessions',
                                  body: {
                                    'guest_count': int.tryParse(guests) ?? 2,
                                  },
                                  success: 'Table visit opened.',
                                );
                              }
                            }
                          : null,
                      icon: const Icon(
                        Icons.add_circle_rounded,
                        color: TablePlayColors.success,
                      ),
                    )
                  : SizedBox(
                      width: 104,
                      child: Row(
                        mainAxisAlignment: MainAxisAlignment.end,
                        children: [
                          IconButton(
                            tooltip: waiterOrdering
                                ? 'Take order'
                                : 'Waiter ordering is not included in this plan',
                            onPressed: waiterOrdering
                                ? () => _takeWaiterOrder(
                                    table,
                                    session as Map,
                                    menu,
                                  )
                                : null,
                            icon: const Icon(
                              Icons.add_shopping_cart_rounded,
                              color: TablePlayColors.accent,
                            ),
                          ),
                          IconButton(
                            tooltip: 'Request bill',
                            onPressed: () async {
                              if (await confirm(
                                'Request bill?',
                                'Notify Counter that ${table['table_code']} is ready for payment.',
                              )) {
                                act(
                                  '/waiter/sessions/${session['id']}/request-bill',
                                  success: 'Counter notified for payment.',
                                );
                              }
                            },
                            icon: const Icon(Icons.receipt_long_rounded),
                          ),
                        ],
                      ),
                    ),
            ),
          );
        }),
      ],
    ]);
  }

  String _requestUuid() {
    final random = Random.secure();
    final bytes = List<int>.generate(16, (_) => random.nextInt(256));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    final value = bytes
        .map((byte) => byte.toRadixString(16).padLeft(2, '0'))
        .join();
    return '${value.substring(0, 8)}-${value.substring(8, 12)}-${value.substring(12, 16)}-${value.substring(16, 20)}-${value.substring(20)}';
  }

  Future<void> _takeWaiterOrder(Map table, Map session, List categories) async {
    final quantities = <int, int>{};
    final note = TextEditingController();
    final items = categories
        .expand((category) => (category['menu_items'] as List? ?? []))
        .map((item) => Map<String, dynamic>.from(item as Map))
        .toList();
    final submit = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (sheetContext) => StatefulBuilder(
        builder: (_, setSheet) {
          final count = quantities.values.fold<int>(
            0,
            (sum, value) => sum + value,
          );
          final total = items.fold<double>(
            0,
            (sum, item) =>
                sum +
                ((item['effective_price'] ?? item['price']) as num).toDouble() *
                    (quantities[(item['id'] as num).toInt()] ?? 0),
          );
          return DraggableScrollableSheet(
            expand: false,
            initialChildSize: .9,
            maxChildSize: .96,
            minChildSize: .6,
            builder: (_, controller) => Padding(
              padding: const EdgeInsets.all(18),
              child: Column(
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              'Take order · ${table['table_code']}',
                              style: const TextStyle(
                                fontSize: 22,
                                fontWeight: FontWeight.w900,
                              ),
                            ),
                            Text(
                              '${session['guest_count']} guests · waiter order',
                              style: const TextStyle(
                                color: TablePlayColors.muted,
                              ),
                            ),
                          ],
                        ),
                      ),
                      IconButton(
                        onPressed: () => Navigator.pop(sheetContext, false),
                        icon: const Icon(Icons.close_rounded),
                      ),
                    ],
                  ),
                  const SizedBox(height: 10),
                  Expanded(
                    child: ListView(
                      controller: controller,
                      children: [
                        ...categories.expand(
                          (category) => [
                            Padding(
                              padding: const EdgeInsets.only(
                                top: 12,
                                bottom: 6,
                              ),
                              child: Text(
                                '${category['name']}',
                                style: const TextStyle(
                                  fontSize: 17,
                                  fontWeight: FontWeight.w900,
                                ),
                              ),
                            ),
                            ...(category['menu_items'] as List? ?? []).map((
                              raw,
                            ) {
                              final item = Map<String, dynamic>.from(
                                raw as Map,
                              );
                              final id = (item['id'] as num).toInt();
                              final qty = quantities[id] ?? 0;
                              return Card(
                                child: ListTile(
                                  title: Text(
                                    '${item['name']}',
                                    style: const TextStyle(
                                      fontWeight: FontWeight.w800,
                                    ),
                                  ),
                                  subtitle: Text(
                                    '₹${item['effective_price'] ?? item['price']} · ${item['short_description'] ?? item['description'] ?? ''}',
                                    maxLines: 2,
                                    overflow: TextOverflow.ellipsis,
                                  ),
                                  trailing: Row(
                                    mainAxisSize: MainAxisSize.min,
                                    children: [
                                      IconButton(
                                        onPressed: qty == 0
                                            ? null
                                            : () => setSheet(
                                                () => quantities[id] = qty - 1,
                                              ),
                                        icon: const Icon(
                                          Icons.remove_circle_outline,
                                        ),
                                      ),
                                      Text(
                                        '$qty',
                                        style: const TextStyle(
                                          fontWeight: FontWeight.w900,
                                        ),
                                      ),
                                      IconButton(
                                        onPressed: () => setSheet(
                                          () => quantities[id] = qty + 1,
                                        ),
                                        icon: const Icon(
                                          Icons.add_circle,
                                          color: TablePlayColors.accent,
                                        ),
                                      ),
                                    ],
                                  ),
                                ),
                              );
                            }),
                          ],
                        ),
                        TextField(
                          controller: note,
                          maxLines: 2,
                          decoration: const InputDecoration(
                            labelText: 'Order note',
                            prefixIcon: Icon(Icons.edit_note_rounded),
                          ),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 12),
                  FilledButton.icon(
                    onPressed: count == 0
                        ? null
                        : () => Navigator.pop(sheetContext, true),
                    icon: const Icon(Icons.send_rounded),
                    label: Text(
                      'Place $count item${count == 1 ? '' : 's'} · ₹${total.toStringAsFixed(2)}',
                    ),
                    style: FilledButton.styleFrom(
                      minimumSize: const Size.fromHeight(52),
                    ),
                  ),
                ],
              ),
            ),
          );
        },
      ),
    );
    if (submit == true) {
      try {
        await api.post('/waiter/sessions/${session['id']}/orders', {
          'client_request_id': _requestUuid(),
          'customer_note': note.text.trim(),
          'items': quantities.entries
              .where((entry) => entry.value > 0)
              .map(
                (entry) => {'menu_item_id': entry.key, 'quantity': entry.value},
              )
              .toList(),
        });
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(
              content: Text('Order sent to Counter and Kitchen.'),
              backgroundColor: TablePlayColors.success,
            ),
          );
        }
        await load(silent: true, force: true);
      } catch (exception) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content: Text(exception.toString()),
              backgroundColor: TablePlayColors.danger,
            ),
          );
        }
      }
    }
    note.dispose();
  }

  Widget _order(
    Map order, {
    bool counter = false,
    bool kitchen = false,
    bool waiter = false,
  }) {
    final items = order['items'] as List? ?? [];
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(15),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                CircleAvatar(
                  backgroundColor: TablePlayColors.deep,
                  foregroundColor: Colors.white,
                  child: Text(
                    tableCode(order),
                    style: const TextStyle(
                      fontSize: 10,
                      fontWeight: FontWeight.w900,
                    ),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        '${order['order_number']}',
                        style: const TextStyle(fontWeight: FontWeight.w900),
                      ),
                      Text(
                        '${items.fold<int>(0, (sum, item) => sum + (item['quantity'] as num).toInt())} items',
                        style: const TextStyle(
                          fontSize: 10,
                          color: TablePlayColors.muted,
                        ),
                      ),
                    ],
                  ),
                ),
                StatusPill('${order['status']}'),
              ],
            ),
            const Divider(height: 22),
            ...items.map(
              (item) => Padding(
                padding: const EdgeInsets.only(bottom: 5),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      '${item['quantity']}× ',
                      style: const TextStyle(
                        fontWeight: FontWeight.w900,
                        color: TablePlayColors.accent,
                      ),
                    ),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            '${item['item_name_snapshot']}',
                            style: const TextStyle(fontWeight: FontWeight.w700),
                          ),
                          if (item['special_instruction'] != null)
                            Text(
                              '${item['special_instruction']}',
                              style: const TextStyle(
                                fontSize: 10,
                                color: TablePlayColors.danger,
                              ),
                            ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ),
            if (order['customer_note'] != null)
              Container(
                margin: const EdgeInsets.only(top: 7),
                padding: const EdgeInsets.all(9),
                decoration: BoxDecoration(
                  color: const Color(0xfffff3da),
                  borderRadius: BorderRadius.circular(9),
                ),
                child: Text(
                  'Guest: ${order['customer_note']}',
                  style: const TextStyle(fontSize: 10),
                ),
              ),
            const SizedBox(height: 11),
            if (counter && order['status'] == 'pending')
              Row(
                children: [
                  Expanded(
                    child: OutlinedButton(
                      onPressed: () async {
                        final reason = await ask('Reject order', 'Reason');
                        if (reason != null) {
                          act(
                            '/counter/orders/${order['id']}/reject',
                            body: {'reason': reason},
                            success: 'Order rejected.',
                          );
                        }
                      },
                      child: const Text('Reject'),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: FilledButton(
                      onPressed: () => act(
                        '/counter/orders/${order['id']}/confirm',
                        success: _featureAllowed('games')
                            ? 'Order confirmed and games unlocked.'
                            : 'Order confirmed.',
                      ),
                      child: const Text('Confirm'),
                    ),
                  ),
                ],
              ),
            if (kitchen)
              SizedBox(
                width: double.infinity,
                child: FilledButton(
                  onPressed: () {
                    final next = order['status'] == 'confirmed'
                        ? 'preparing'
                        : order['status'] == 'preparing'
                        ? 'ready'
                        : 'served';
                    act(
                      '/kitchen/orders/${order['id']}/$next',
                      success: 'Order marked $next.',
                    );
                  },
                  child: Text(
                    order['status'] == 'confirmed'
                        ? 'Accept & start preparing'
                        : order['status'] == 'preparing'
                        ? 'Mark ready'
                        : 'Mark served',
                  ),
                ),
              ),
            if (waiter)
              SizedBox(
                width: double.infinity,
                child: FilledButton.icon(
                  onPressed: () => act(
                    '/waiter/orders/${order['id']}/served',
                    success: 'Order marked served.',
                  ),
                  icon: const Icon(Icons.room_service_rounded),
                  label: const Text('Mark served'),
                ),
              ),
          ],
        ),
      ),
    );
  }

  Widget _empty(String message) => Card(
    child: Padding(
      padding: const EdgeInsets.all(28),
      child: Column(
        children: [
          const Icon(
            Icons.check_circle_outline_rounded,
            color: TablePlayColors.success,
            size: 38,
          ),
          const SizedBox(height: 8),
          Text(message, style: const TextStyle(fontWeight: FontWeight.w800)),
        ],
      ),
    ),
  );
}

class _StaffHelpSheet extends StatefulWidget {
  const _StaffHelpSheet({
    required this.api,
    required this.role,
    required this.onNavigate,
  });

  final ApiClient api;
  final String role;
  final ValueChanged<String?> onNavigate;

  @override
  State<_StaffHelpSheet> createState() => _StaffHelpSheetState();
}

class _StaffHelpSheetState extends State<_StaffHelpSheet> {
  final input = TextEditingController();
  final scroll = ScrollController();
  final List<Map<String, dynamic>> messages = [
    {
      'user': false,
      'title': 'How can I help?',
      'reply':
          'Ask me how to use any TablePlay screen or complete a restaurant task. I work on your local server without internet.',
      'steps': <dynamic>[],
    },
  ];
  bool sending = false;
  late List<String> suggestions = switch (widget.role) {
    'admin' => ['Pair a tablet', 'Add a menu item', 'Check system health'],
    'counter' => ['Confirm an order', 'Generate a bill', 'Extend game time'],
    'kitchen' => [
      'Start preparing an order',
      'Mark food ready',
      'What does overdue mean?',
    ],
    'waiter' => [
      'Handle a guest request',
      'Serve a ready order',
      'Request a bill',
    ],
    _ => ['How do I get started?'],
  };

  Future<void> send([String? suggestion]) async {
    final message = (suggestion ?? input.text).trim();
    if (message.length < 2 || sending) return;
    input.clear();
    setState(() {
      messages.add({'user': true, 'reply': message});
      sending = true;
    });
    _scrollDown();
    try {
      final response = Map<String, dynamic>.from(
        await widget.api.post('/help/chat', {'message': message}),
      );
      if (!mounted) return;
      setState(() {
        messages.add({'user': false, ...response});
        suggestions = (response['suggestions'] as List? ?? [])
            .map((value) => '$value')
            .toList();
      });
    } catch (exception) {
      if (!mounted) return;
      setState(() {
        messages.add({
          'user': false,
          'title': 'Connection help',
          'reply':
              'I could not reach the local help service. Check the laptop server and try again.\n\n$exception',
          'steps': <dynamic>[],
        });
      });
    } finally {
      if (mounted) setState(() => sending = false);
      _scrollDown();
    }
  }

  void _scrollDown() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (scroll.hasClients) {
        scroll.animateTo(
          scroll.position.maxScrollExtent,
          duration: const Duration(milliseconds: 220),
          curve: Curves.easeOut,
        );
      }
    });
  }

  @override
  void dispose() {
    input.dispose();
    scroll.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => SizedBox(
    height: MediaQuery.sizeOf(context).height * .88,
    child: Column(
      children: [
        Container(
          padding: const EdgeInsets.fromLTRB(18, 14, 10, 14),
          decoration: const BoxDecoration(
            color: TablePlayColors.deep,
            borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
          ),
          child: Row(
            children: [
              Container(
                width: 43,
                height: 43,
                decoration: BoxDecoration(
                  color: Colors.white.withValues(alpha: .12),
                  borderRadius: BorderRadius.circular(13),
                ),
                child: const Icon(
                  Icons.smart_toy_rounded,
                  color: TablePlayColors.gold,
                ),
              ),
              const SizedBox(width: 11),
              const Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'TablePlay Help',
                      style: TextStyle(
                        color: Colors.white,
                        fontWeight: FontWeight.w900,
                        fontSize: 16,
                      ),
                    ),
                    Text(
                      'Role-aware · local · no internet needed',
                      style: TextStyle(color: Colors.white60, fontSize: 10),
                    ),
                  ],
                ),
              ),
              IconButton(
                onPressed: () => Navigator.pop(context),
                color: Colors.white,
                icon: const Icon(Icons.close_rounded),
              ),
            ],
          ),
        ),
        Expanded(
          child: ListView.builder(
            controller: scroll,
            padding: const EdgeInsets.all(16),
            itemCount: messages.length + (sending ? 1 : 0),
            itemBuilder: (_, index) {
              if (index == messages.length) {
                return const Align(
                  alignment: Alignment.centerLeft,
                  child: Padding(
                    padding: EdgeInsets.all(12),
                    child: SizedBox.square(
                      dimension: 20,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    ),
                  ),
                );
              }
              return _HelpBubble(
                message: messages[index],
                onNavigate: widget.onNavigate,
              );
            },
          ),
        ),
        if (suggestions.isNotEmpty)
          SizedBox(
            height: 47,
            child: ListView.separated(
              padding: const EdgeInsets.symmetric(horizontal: 14),
              scrollDirection: Axis.horizontal,
              itemCount: suggestions.length,
              separatorBuilder: (_, _) => const SizedBox(width: 7),
              itemBuilder: (_, index) => ActionChip(
                onPressed: sending ? null : () => send(suggestions[index]),
                avatar: const Icon(Icons.auto_awesome_rounded, size: 15),
                label: Text(suggestions[index]),
              ),
            ),
          ),
        const Divider(height: 1),
        Padding(
          padding: EdgeInsets.fromLTRB(
            14,
            10,
            14,
            MediaQuery.viewInsetsOf(context).bottom + 12,
          ),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Expanded(
                child: TextField(
                  controller: input,
                  enabled: !sending,
                  minLines: 1,
                  maxLines: 3,
                  textInputAction: TextInputAction.send,
                  onSubmitted: (_) => send(),
                  decoration: const InputDecoration(
                    hintText: 'Ask how to use TablePlay…',
                    prefixIcon: Icon(Icons.help_outline_rounded),
                  ),
                ),
              ),
              const SizedBox(width: 8),
              IconButton.filled(
                onPressed: sending ? null : send,
                icon: const Icon(Icons.send_rounded),
                style: IconButton.styleFrom(minimumSize: const Size(50, 50)),
              ),
            ],
          ),
        ),
      ],
    ),
  );
}

class _HelpBubble extends StatelessWidget {
  const _HelpBubble({required this.message, required this.onNavigate});

  final Map<String, dynamic> message;
  final ValueChanged<String?> onNavigate;

  @override
  Widget build(BuildContext context) {
    final user = message['user'] == true;
    final steps = message['steps'] as List? ?? [];
    final action = message['action'] as Map?;
    return Align(
      alignment: user ? Alignment.centerRight : Alignment.centerLeft,
      child: Container(
        constraints: const BoxConstraints(maxWidth: 560),
        margin: const EdgeInsets.only(bottom: 10),
        padding: const EdgeInsets.all(13),
        decoration: BoxDecoration(
          color: user ? TablePlayColors.deep : Colors.white,
          borderRadius: BorderRadius.only(
            topLeft: const Radius.circular(16),
            topRight: const Radius.circular(16),
            bottomLeft: Radius.circular(user ? 16 : 4),
            bottomRight: Radius.circular(user ? 4 : 16),
          ),
          border: user ? null : Border.all(color: const Color(0xffdfe7e1)),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (message['title'] != null) ...[
              Text(
                '${message['title']}',
                style: TextStyle(
                  color: user ? Colors.white : TablePlayColors.deep,
                  fontWeight: FontWeight.w900,
                ),
              ),
              const SizedBox(height: 5),
            ],
            Text(
              '${message['reply']}',
              style: TextStyle(
                color: user ? Colors.white : TablePlayColors.muted,
                height: 1.4,
              ),
            ),
            if (steps.isNotEmpty) ...[
              const SizedBox(height: 9),
              ...steps.indexed.map(
                (entry) => Padding(
                  padding: const EdgeInsets.only(bottom: 5),
                  child: Text(
                    '${entry.$1 + 1}. ${entry.$2}',
                    style: const TextStyle(fontSize: 11.5, height: 1.35),
                  ),
                ),
              ),
            ],
            if (action != null) ...[
              const SizedBox(height: 8),
              FilledButton.tonalIcon(
                onPressed: () => onNavigate('${action['url']}'),
                icon: const Icon(Icons.arrow_forward_rounded, size: 17),
                label: Text('${action['label']}'),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _GameTimerCard extends StatefulWidget {
  const _GameTimerCard({
    required this.game,
    required this.onExtend,
    required this.onCustom,
    required this.onStop,
  });

  final Map<String, dynamic> game;
  final Future<void> Function(int minutes) onExtend;
  final Future<void> Function() onCustom;
  final Future<void> Function() onStop;

  @override
  State<_GameTimerCard> createState() => _GameTimerCardState();
}

class _GameTimerCardState extends State<_GameTimerCard> {
  Timer? ticker;
  int remaining = 0;
  bool working = false;

  @override
  void initState() {
    super.initState();
    _calculateRemaining();
    ticker = Timer.periodic(const Duration(seconds: 1), (_) {
      if (mounted && remaining > 0) setState(() => remaining--);
    });
  }

  @override
  void didUpdateWidget(covariant _GameTimerCard oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.game['expires_at'] != widget.game['expires_at']) {
      _calculateRemaining();
    }
  }

  void _calculateRemaining() {
    final expires = DateTime.tryParse(
      '${widget.game['expires_at']}',
    )?.toLocal();
    remaining = expires == null
        ? 0
        : max(0, expires.difference(DateTime.now()).inSeconds);
  }

  Future<void> _run(Future<void> Function() action) async {
    if (working) return;
    setState(() => working = true);
    try {
      await action();
    } finally {
      if (mounted) setState(() => working = false);
    }
  }

  String get clock {
    final hours = remaining ~/ 3600;
    final minutes = (remaining % 3600) ~/ 60;
    final seconds = remaining % 60;
    return hours > 0
        ? '$hours:${minutes.toString().padLeft(2, '0')}:${seconds.toString().padLeft(2, '0')}'
        : '${minutes.toString().padLeft(2, '0')}:${seconds.toString().padLeft(2, '0')}';
  }

  @override
  void dispose() {
    ticker?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final table =
        widget.game['table_session']?['dining_table']?['table_code'] ?? 'Table';
    final warning = remaining <= 300;
    final color = warning ? const Color(0xffb66b0b) : TablePlayColors.success;
    return Card(
      clipBehavior: Clip.antiAlias,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Container(
                  width: 44,
                  height: 44,
                  decoration: BoxDecoration(
                    color: TablePlayColors.deep.withValues(alpha: .08),
                    borderRadius: BorderRadius.circular(14),
                  ),
                  child: const Icon(
                    Icons.sports_esports_rounded,
                    color: TablePlayColors.deep,
                  ),
                ),
                const SizedBox(width: 11),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        '$table',
                        style: const TextStyle(
                          fontSize: 16,
                          fontWeight: FontWeight.w900,
                        ),
                      ),
                      const Text(
                        'Server-controlled access',
                        style: TextStyle(
                          color: TablePlayColors.muted,
                          fontSize: 10.5,
                        ),
                      ),
                    ],
                  ),
                ),
                Column(
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: [
                    Text(
                      clock,
                      style: TextStyle(
                        color: color,
                        fontSize: 20,
                        fontWeight: FontWeight.w900,
                        fontFeatures: const [FontFeature.tabularFigures()],
                      ),
                    ),
                    Text(
                      remaining == 0 ? 'ENDED' : 'REMAINING',
                      style: TextStyle(
                        color: color,
                        fontSize: 8,
                        fontWeight: FontWeight.w900,
                        letterSpacing: 1,
                      ),
                    ),
                  ],
                ),
              ],
            ),
            const SizedBox(height: 13),
            ClipRRect(
              borderRadius: BorderRadius.circular(99),
              child: LinearProgressIndicator(
                minHeight: 5,
                value: remaining == 0 ? 0 : null,
                color: color,
                backgroundColor: color.withValues(alpha: .12),
              ),
            ),
            const SizedBox(height: 13),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                OutlinedButton(
                  onPressed: working || remaining == 0
                      ? null
                      : () => _run(() => widget.onExtend(15)),
                  child: const Text('+15 min'),
                ),
                OutlinedButton(
                  onPressed: working || remaining == 0
                      ? null
                      : () => _run(() => widget.onExtend(30)),
                  child: const Text('+30 min'),
                ),
                OutlinedButton.icon(
                  onPressed: working || remaining == 0
                      ? null
                      : () => _run(widget.onCustom),
                  icon: const Icon(Icons.more_time_rounded, size: 18),
                  label: const Text('Custom'),
                ),
                FilledButton.icon(
                  onPressed: working || remaining == 0
                      ? null
                      : () => _run(widget.onStop),
                  icon: const Icon(Icons.stop_circle_outlined, size: 18),
                  label: const Text('Stop'),
                  style: FilledButton.styleFrom(
                    backgroundColor: TablePlayColors.danger,
                  ),
                ),
              ],
            ),
            if (working) ...[
              const SizedBox(height: 10),
              const LinearProgressIndicator(minHeight: 2),
            ],
          ],
        ),
      ),
    );
  }
}
