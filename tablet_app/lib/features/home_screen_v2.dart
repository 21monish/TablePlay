import 'dart:async';
import 'package:flutter/material.dart';
import 'package:tableplay_update/tableplay_update.dart';
import '../core/api_client.dart';
import '../core/device_store.dart';
import '../theme/tableplay_theme.dart';
import '../widgets/tableplay_brand.dart';
import 'game_center_v2.dart';
import 'customer_menu.dart';

class CartLineV2 {
  CartLineV2(this.item, {this.quantity = 1});
  final Map<String, dynamic> item;
  int quantity;
  String instruction = '';
  double get unitPrice {
    final value =
        item['effective_price'] ?? item['discount_price'] ?? item['price'];
    return value is num ? value.toDouble() : double.parse(value.toString());
  }

  double get total => unitPrice * quantity;
}

class _CustomerDestination {
  const _CustomerDestination(
      this.label, this.subtitle, this.icon, this.selectedIcon);

  final String label;
  final String subtitle;
  final IconData icon;
  final IconData selectedIcon;
}

class HomeScreenV2 extends StatefulWidget {
  const HomeScreenV2({super.key, required this.store, required this.onReset});
  final DeviceStore store;
  final VoidCallback onReset;
  @override
  State<HomeScreenV2> createState() => _HomeScreenV2State();
}

class _HomeScreenV2State extends State<HomeScreenV2>
    with WidgetsBindingObserver {
  static const destinations = [
    _CustomerDestination('Menu', 'Browse food and drinks',
        Icons.restaurant_menu_outlined, Icons.restaurant_menu_rounded),
    _CustomerDestination('My orders', 'Follow kitchen progress',
        Icons.receipt_long_outlined, Icons.receipt_long_rounded),
    _CustomerDestination('Game lounge', 'Play after confirmation',
        Icons.sports_esports_outlined, Icons.sports_esports_rounded),
    _CustomerDestination('Table service', 'Waiter, water, and bill',
        Icons.room_service_outlined, Icons.room_service_rounded),
  ];

  late final ApiClient api;
  Timer? polling;
  int tab = 0, guestCount = 2;
  bool loading = true, openingVisit = false;
  bool planAccessDenied = false;
  String? error;
  String? planAccessMessage;
  Map<String, dynamic>? session;
  List<dynamic> categories = [], orders = [];
  final Map<int, CartLineV2> cart = {};
  Map<String, dynamic> game = {'unlocked': false, 'remaining_seconds': 0};
  Map<String, dynamic> branding = {};
  DateTime? _brandingLoadedAt;
  DateTime? _menuLoadedAt;
  bool _refreshingState = false;

  int get cartCount => cart.values.fold(0, (sum, line) => sum + line.quantity);
  double get cartTotal => cart.values.fold(0, (sum, line) => sum + line.total);

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    api = ApiClient(baseUrl: widget.store.baseUrl)
      ..deviceUuid = widget.store.uuid
      ..deviceToken = widget.store.token;
    loadAll();
    _startPolling();
  }

  void _startPolling() {
    polling?.cancel();
    polling = Timer.periodic(const Duration(seconds: 6), (_) => refreshState());
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      _startPolling();
      refreshState();
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

  Future<void> loadAll() async {
    try {
      await _loadBranding();
      await _loadMenu();
      await refreshState(notify: false);
    } on ApiException catch (exception) {
      if (exception.isPlanAccessDenied) {
        _applyPlanDenial(exception);
      } else {
        error = exception.toString();
      }
    } catch (exception) {
      error = exception.toString();
    } finally {
      if (mounted) setState(() => loading = false);
    }
  }

  Future<void> refreshState({bool notify = true}) async {
    if (_refreshingState) return;
    _refreshingState = true;
    try {
      if (_brandingLoadedAt == null ||
          DateTime.now().difference(_brandingLoadedAt!).inSeconds >= 60) {
        await _loadBranding();
      }
      if (_menuLoadedAt == null ||
          DateTime.now().difference(_menuLoadedAt!).inSeconds >= 60) {
        await _loadMenu();
      }
      final snapshot =
          Map<String, dynamic>.from(await api.get('/table/snapshot'));
      session = Map<String, dynamic>.from(snapshot['session']);
      orders = snapshot['orders'] as List? ?? [];
      game = Map<String, dynamic>.from(snapshot['game']);
      planAccessDenied = false;
      planAccessMessage = null;
      error = null;
    } on ApiException catch (exception) {
      if (exception.isPlanAccessDenied) {
        _applyPlanDenial(exception);
      } else if (exception.status == 404) {
        session = null;
        orders = [];
        game = {'unlocked': false, 'remaining_seconds': 0};
        planAccessDenied = false;
        planAccessMessage = null;
        error = null;
      } else {
        error = exception.toString();
      }
    } catch (exception) {
      error = exception.toString();
    } finally {
      _refreshingState = false;
    }
    if (mounted && notify) setState(() {});
  }

  Future<void> _loadBranding() async {
    try {
      branding = Map<String, dynamic>.from(await api.get('/branding'));
      _brandingLoadedAt = DateTime.now();
    } catch (_) {
      // Ordering stays usable if the optional branding refresh is unavailable.
    }
  }

  Future<void> _loadMenu() async {
    categories = await api.get('/menu') as List<dynamic>;
    _menuLoadedAt = DateTime.now();
  }

  Future<void> openVisit() async {
    setState(() {
      openingVisit = true;
      error = null;
    });
    try {
      session = Map<String, dynamic>.from(
          await api.post('/table-sessions', {'guest_count': guestCount}));
      await refreshState();
    } on ApiException catch (exception) {
      if (exception.isPlanAccessDenied) {
        _applyPlanDenial(exception);
      } else if (mounted) {
        setState(() => error = exception.toString());
      }
    } catch (exception) {
      if (mounted) setState(() => error = exception.toString());
    } finally {
      if (mounted) setState(() => openingVisit = false);
    }
  }

  void add(Map<String, dynamic> item, [String instruction = '']) {
    setState(() => cart.update(item['id'], (line) {
          line.quantity++;
          if (instruction.isNotEmpty) line.instruction = instruction;
          return line;
        }, ifAbsent: () => CartLineV2(item)..instruction = instruction));
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        duration: const Duration(milliseconds: 900),
        content: Text('${item['name']} added to your cart')));
  }

  Future<void> showCart() async {
    final orderNote = TextEditingController();
    await showModalBottomSheet<void>(
        context: context,
        isScrollControlled: true,
        backgroundColor: Colors.transparent,
        builder: (sheetContext) => StatefulBuilder(
            builder: (_, setSheet) => Container(
                  constraints: BoxConstraints(
                      maxHeight: MediaQuery.sizeOf(sheetContext).height * .82),
                  decoration: const BoxDecoration(
                      color: Colors.white,
                      borderRadius:
                          BorderRadius.vertical(top: Radius.circular(26))),
                  child: SafeArea(
                      child: Column(mainAxisSize: MainAxisSize.min, children: [
                    Container(
                        width: 42,
                        height: 4,
                        margin: const EdgeInsets.only(top: 11),
                        decoration: BoxDecoration(
                            color: TablePlayColors.border,
                            borderRadius: BorderRadius.circular(99))),
                    Padding(
                        padding: const EdgeInsets.fromLTRB(22, 18, 16, 12),
                        child: Row(children: [
                          Expanded(
                              child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                Text('Your cart',
                                    style: Theme.of(context)
                                        .textTheme
                                        .headlineSmall
                                        ?.copyWith(
                                            fontWeight: FontWeight.w900)),
                                Text(
                                    '$cartCount item${cartCount == 1 ? '' : 's'} ready to order',
                                    style: const TextStyle(
                                        color: TablePlayColors.muted))
                              ])),
                          IconButton(
                              onPressed: () => Navigator.pop(sheetContext),
                              icon: const Icon(Icons.close))
                        ])),
                    const Divider(height: 1),
                    Flexible(
                        child: ListView(
                            padding: const EdgeInsets.all(18),
                            children: [
                          ...cart.values.map((line) => Container(
                              margin: const EdgeInsets.only(bottom: 12),
                              padding: const EdgeInsets.all(14),
                              decoration: BoxDecoration(
                                  color: TablePlayColors.canvas,
                                  borderRadius: BorderRadius.circular(16)),
                              child: Column(children: [
                                Row(children: [
                                  Expanded(
                                      child: Column(
                                          crossAxisAlignment:
                                              CrossAxisAlignment.start,
                                          children: [
                                        Text(line.item['name'],
                                            style: const TextStyle(
                                                fontWeight: FontWeight.w800)),
                                        const SizedBox(height: 3),
                                        Text(
                                            '₹${line.total.toStringAsFixed(2)}',
                                            style: const TextStyle(
                                                color: TablePlayColors.accent,
                                                fontWeight: FontWeight.w800))
                                      ])),
                                  Container(
                                      decoration: BoxDecoration(
                                          color: Colors.white,
                                          borderRadius:
                                              BorderRadius.circular(12)),
                                      child: Row(children: [
                                        IconButton(
                                            onPressed: () {
                                              setState(() {
                                                line.quantity--;
                                                if (line.quantity <= 0) {
                                                  cart.remove(line.item['id']);
                                                }
                                              });
                                              setSheet(() {});
                                            },
                                            icon: const Icon(Icons.remove,
                                                size: 19)),
                                        Text('${line.quantity}',
                                            style: const TextStyle(
                                                fontWeight: FontWeight.w900)),
                                        IconButton(
                                            onPressed: () {
                                              setState(() => line.quantity++);
                                              setSheet(() {});
                                            },
                                            icon:
                                                const Icon(Icons.add, size: 19))
                                      ]))
                                ]),
                                const SizedBox(height: 10),
                                TextFormField(
                                    initialValue: line.instruction,
                                    decoration: const InputDecoration(
                                        hintText:
                                            'Special instruction for this item',
                                        prefixIcon:
                                            Icon(Icons.edit_note_rounded),
                                        isDense: true),
                                    onChanged: (value) =>
                                        line.instruction = value),
                              ]))),
                          TextField(
                              controller: orderNote,
                              maxLines: 2,
                              decoration: const InputDecoration(
                                  labelText:
                                      'Note for the whole order (optional)',
                                  prefixIcon:
                                      Icon(Icons.chat_bubble_outline_rounded))),
                        ])),
                    Padding(
                        padding: const EdgeInsets.fromLTRB(18, 10, 18, 16),
                        child: FilledButton.icon(
                            onPressed: cart.isEmpty
                                ? null
                                : () {
                                    final note = orderNote.text.trim();
                                    Navigator.pop(sheetContext);
                                    checkout(note);
                                  },
                            icon: const Icon(Icons.arrow_forward_rounded),
                            label: Text(
                                'Place order · ₹${cartTotal.toStringAsFixed(2)}'),
                            style: FilledButton.styleFrom(
                                minimumSize: const Size.fromHeight(54),
                                backgroundColor: TablePlayColors.accent))),
                  ])),
                )));
    orderNote.dispose();
  }

  Future<void> checkout(String note) async {
    if (cart.isEmpty) return;
    try {
      await api.post('/orders', {
        'items': cart.values
            .map((line) => {
                  'menu_item_id': line.item['id'],
                  'quantity': line.quantity,
                  'special_instruction': line.instruction.trim().isEmpty
                      ? null
                      : line.instruction.trim()
                })
            .toList(),
        'customer_note': note.isEmpty ? null : note
      });
      cart.clear();
      tab = 1;
      await refreshState();
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
            content: Text('Order sent to the counter for confirmation.')));
      }
    } on ApiException catch (exception) {
      if (exception.isPlanAccessDenied) {
        _applyPlanDenial(exception);
        if (mounted) setState(() {});
      } else if (mounted) {
        setState(() => error = exception.toString());
      }
    } catch (exception) {
      if (mounted) setState(() => error = exception.toString());
    }
  }

  Future<void> requestService(String type, String label) async {
    try {
      await api.post('/service-requests', {'request_type': type});
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text('$label request sent.')));
      }
    } on ApiException catch (exception) {
      if (exception.isPlanAccessDenied) {
        _applyPlanDenial(exception);
        if (mounted) setState(() {});
      } else if (mounted) {
        setState(() => error = exception.toString());
      }
    } catch (exception) {
      if (mounted) setState(() => error = exception.toString());
    }
  }

  void _applyPlanDenial(ApiException exception) {
    planAccessDenied = true;
    planAccessMessage = exception.toString();
    error = null;
    cart.clear();
  }

  Future<void> reset() async {
    await widget.store.clear();
    widget.onReset();
  }

  Future<void> requestReset() async {
    final confirmed = await showDialog<bool>(
          context: context,
          builder: (dialogContext) => AlertDialog(
            icon: const Icon(Icons.link_off_rounded,
                color: TablePlayColors.accent, size: 34),
            title: const Text('Reset tablet pairing?',
                textAlign: TextAlign.center),
            content: Text(
                '${widget.store.tableCode} will be disconnected from this device. An Admin must pair it again.',
                textAlign: TextAlign.center),
            actionsAlignment: MainAxisAlignment.center,
            actions: [
              OutlinedButton(
                  onPressed: () => Navigator.pop(dialogContext, false),
                  child: const Text('Keep pairing')),
              FilledButton(
                  onPressed: () => Navigator.pop(dialogContext, true),
                  style: FilledButton.styleFrom(
                      backgroundColor: TablePlayColors.accent),
                  child: const Text('Reset pairing')),
            ],
          ),
        ) ??
        false;
    if (confirmed) await reset();
  }

  @override
  Widget build(BuildContext context) {
    if (loading) {
      return const Scaffold(
          body: Center(
              child: Column(mainAxisSize: MainAxisSize.min, children: [
        TablePlayMark(size: 66),
        SizedBox(height: 20),
        CircularProgressIndicator(),
        SizedBox(height: 12),
        Text('Connecting to your table…',
            style: TextStyle(color: TablePlayColors.muted))
      ])));
    }
    if (planAccessDenied) return _planAccessDeniedScreen();
    final pages = [
      menuPage(),
      ordersPage(),
      GameCenterV2(api: api, access: game),
      servicesPage()
    ];
    return LayoutBuilder(builder: (context, constraints) {
      final wide = constraints.maxWidth >= 900;
      final destination = destinations[tab];
      final page = session == null ? startVisit() : pages[tab];

      return Scaffold(
        drawer:
            wide ? null : Drawer(child: _navigationPanel(closeDrawer: true)),
        appBar: AppBar(
            titleSpacing: wide ? 24 : 6,
            title: wide
                ? Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                        Text(destination.label),
                        Text(destination.subtitle,
                            style: const TextStyle(
                                fontSize: 10,
                                color: Colors.white60,
                                fontWeight: FontWeight.w600))
                      ])
                : TablePlayBrand(
                    light: true,
                    compact: true,
                    logoUrl: '${branding['app_logo_url'] ?? ''}',
                    title: '${branding['restaurant_name'] ?? 'TablePlay'}',
                    tagline:
                        '${branding['tagline'] ?? 'DINE · PLAY · DELIGHT'}',
                  ),
            actions: [
              if (session != null && cart.isNotEmpty)
                IconButton(
                    onPressed: showCart,
                    tooltip: 'Open cart',
                    icon: Badge(
                        label: Text('$cartCount'),
                        child: const Icon(Icons.shopping_bag_outlined))),
              IconButton(
                  onPressed: refreshState,
                  tooltip: 'Refresh table',
                  icon: const Icon(Icons.refresh_rounded)),
              const SizedBox(width: 6),
            ]),
        body: Row(children: [
          if (wide) SizedBox(width: 288, child: _navigationPanel()),
          if (wide) const VerticalDivider(width: 1),
          Expanded(
              child: Column(children: [
            if (error != null)
              MaterialBanner(
                  backgroundColor: const Color(0xffffebe7),
                  leading: const Icon(Icons.wifi_off_rounded,
                      color: Color(0xffc34836)),
                  content: Text(error!, style: const TextStyle(fontSize: 12)),
                  actions: [
                    TextButton(
                        onPressed: refreshState, child: const Text('Retry'))
                  ]),
            if (session != null) _sessionStatusBar(wide: wide),
            Expanded(
                child: Center(
                    child: ConstrainedBox(
                        constraints: const BoxConstraints(maxWidth: 1180),
                        child: page))),
          ])),
        ]),
      );
    });
  }

  Widget _sessionStatusBar({required bool wide}) {
    final remaining = (game['remaining_seconds'] as num?)?.toInt() ?? 0;
    final minutes = remaining ~/ 60;
    final seconds = (remaining % 60).toString().padLeft(2, '0');
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
      decoration: const BoxDecoration(
        color: Colors.white,
        border: Border(bottom: BorderSide(color: TablePlayColors.border)),
      ),
      child: SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        child: Row(
          children: [
            _guestContextChip(
              Icons.table_restaurant_rounded,
              widget.store.tableCode ?? 'Table',
              TablePlayColors.deep,
            ),
            const SizedBox(width: 8),
            _guestContextChip(
              Icons.groups_2_outlined,
              '${session?['guest_count'] ?? 0} guests',
              TablePlayColors.info,
            ),
            const SizedBox(width: 8),
            _guestContextChip(
              Icons.receipt_long_outlined,
              '${orders.length} order${orders.length == 1 ? '' : 's'}',
              TablePlayColors.warning,
            ),
            const SizedBox(width: 8),
            _guestContextChip(
              game['unlocked'] == true
                  ? Icons.sports_esports_rounded
                  : Icons.lock_outline_rounded,
              game['unlocked'] == true
                  ? 'Games $minutes:$seconds'
                  : 'Games locked',
              game['unlocked'] == true
                  ? TablePlayColors.success
                  : TablePlayColors.muted,
            ),
            if (cart.isNotEmpty) ...[
              const SizedBox(width: 8),
              _guestContextChip(
                Icons.shopping_bag_outlined,
                '$cartCount in cart',
                TablePlayColors.accent,
              ),
            ],
            if (wide) ...[
              const SizedBox(width: 8),
              _guestContextChip(
                Icons.wifi_rounded,
                'Restaurant network online',
                TablePlayColors.success,
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _guestContextChip(IconData icon, String label, Color color) =>
      Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7),
        decoration: BoxDecoration(
          color: color.withValues(alpha: .07),
          borderRadius: BorderRadius.circular(10),
          border: Border.all(color: color.withValues(alpha: .14)),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 14, color: color),
            const SizedBox(width: 6),
            Text(
              label,
              style: TextStyle(
                color: color,
                fontSize: 9.5,
                fontWeight: FontWeight.w800,
              ),
            ),
          ],
        ),
      );

  Widget _planAccessDeniedScreen() => Scaffold(
        appBar: AppBar(
          title: TablePlayBrand(
            light: true,
            compact: true,
            logoUrl: '${branding['app_logo_url'] ?? ''}',
            title: '${branding['restaurant_name'] ?? 'TablePlay'}',
            tagline: '${branding['tagline'] ?? 'DINE · PLAY · DELIGHT'}',
          ),
        ),
        body: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 560),
              child: Card(
                child: Padding(
                  padding: const EdgeInsets.all(30),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Container(
                        width: 78,
                        height: 78,
                        decoration: BoxDecoration(
                          color: TablePlayColors.gold.withValues(alpha: .18),
                          borderRadius: BorderRadius.circular(24),
                        ),
                        child: const Icon(
                          Icons.workspace_premium_rounded,
                          color: TablePlayColors.accent,
                          size: 40,
                        ),
                      ),
                      const SizedBox(height: 20),
                      Text(
                        'Customer Table access is unavailable',
                        textAlign: TextAlign.center,
                        style: Theme.of(context)
                            .textTheme
                            .headlineSmall
                            ?.copyWith(fontWeight: FontWeight.w900),
                      ),
                      const SizedBox(height: 10),
                      Text(
                        planAccessMessage ??
                            'Ask the restaurant administrator to check the active TablePlay plan.',
                        textAlign: TextAlign.center,
                        style: const TextStyle(
                          color: TablePlayColors.muted,
                          height: 1.5,
                        ),
                      ),
                      const SizedBox(height: 8),
                      const Text(
                        'This screen will unlock automatically after the licence or tablet limit is corrected.',
                        textAlign: TextAlign.center,
                        style: TextStyle(
                          color: TablePlayColors.muted,
                          fontSize: 11,
                        ),
                      ),
                      const SizedBox(height: 24),
                      FilledButton.icon(
                        onPressed: () async {
                          setState(() => loading = true);
                          await loadAll();
                        },
                        icon: const Icon(Icons.refresh_rounded),
                        label: const Text('Check access again'),
                      ),
                      const SizedBox(height: 8),
                      TextButton.icon(
                        onPressed: requestReset,
                        icon: const Icon(Icons.settings_outlined),
                        label: const Text('Tablet pairing settings'),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
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
                    tagline:
                        '${branding['tagline'] ?? 'DINE · PLAY · DELIGHT'}',
                  )),
              Container(
                  margin: const EdgeInsets.fromLTRB(14, 0, 14, 15),
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(
                      color: Colors.white.withValues(alpha: .08),
                      borderRadius: BorderRadius.circular(17),
                      border: Border.all(
                          color: Colors.white.withValues(alpha: .08))),
                  child: Row(children: [
                    Container(
                        width: 44,
                        height: 44,
                        decoration: BoxDecoration(
                            color: TablePlayColors.accent,
                            borderRadius: BorderRadius.circular(13)),
                        child: const Icon(Icons.table_restaurant_rounded,
                            color: Colors.white)),
                    const SizedBox(width: 11),
                    Expanded(
                        child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                          Text(widget.store.tableCode ?? 'Guest table',
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: const TextStyle(
                                  color: Colors.white,
                                  fontWeight: FontWeight.w900)),
                          Text(
                              session == null
                                  ? 'READY TO START A VISIT'
                                  : '${session?['guest_count'] ?? 0} GUESTS · VISIT ACTIVE',
                              style: const TextStyle(
                                  color: Colors.white60,
                                  fontSize: 9,
                                  letterSpacing: .6,
                                  fontWeight: FontWeight.w800))
                        ]))
                  ])),
              const Padding(
                  padding: EdgeInsets.fromLTRB(20, 2, 20, 7),
                  child: Text('YOUR TABLE',
                      style: TextStyle(
                          color: Colors.white38,
                          fontSize: 9,
                          letterSpacing: 1.2,
                          fontWeight: FontWeight.w900))),
              Expanded(
                  child: ListView.builder(
                      padding: const EdgeInsets.symmetric(horizontal: 10),
                      itemCount: destinations.length,
                      itemBuilder: (itemContext, index) {
                        final item = destinations[index];
                        final selected = tab == index;
                        final enabled = session != null;
                        return Padding(
                            padding: const EdgeInsets.only(bottom: 4),
                            child: ListTile(
                                enabled: enabled,
                                selected: selected,
                                selectedTileColor:
                                    Colors.white.withValues(alpha: .12),
                                shape: RoundedRectangleBorder(
                                    borderRadius: BorderRadius.circular(13)),
                                leading: Icon(
                                    selected ? item.selectedIcon : item.icon,
                                    color: !enabled
                                        ? Colors.white30
                                        : selected
                                            ? TablePlayColors.gold
                                            : Colors.white70),
                                title: Text(item.label,
                                    style: TextStyle(
                                        color: enabled
                                            ? Colors.white
                                            : Colors.white38,
                                        fontWeight: selected
                                            ? FontWeight.w900
                                            : FontWeight.w700)),
                                subtitle: Text(item.subtitle,
                                    maxLines: 1,
                                    overflow: TextOverflow.ellipsis,
                                    style: TextStyle(
                                        color: enabled
                                            ? Colors.white54
                                            : Colors.white24,
                                        fontSize: 10)),
                                trailing: _destinationBadge(index),
                                onTap: !enabled
                                    ? null
                                    : () {
                                        setState(() => tab = index);
                                        if (closeDrawer) {
                                          Navigator.pop(itemContext);
                                        }
                                      }));
                      })),
              if (cart.isNotEmpty)
                Container(
                    margin: const EdgeInsets.fromLTRB(12, 4, 12, 8),
                    padding: const EdgeInsets.all(13),
                    decoration: BoxDecoration(
                        color: TablePlayColors.accent,
                        borderRadius: BorderRadius.circular(15)),
                    child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          Row(children: [
                            const Icon(Icons.shopping_bag_rounded,
                                color: Colors.white, size: 20),
                            const SizedBox(width: 8),
                            Expanded(
                                child: Text('$cartCount item cart',
                                    style: const TextStyle(
                                        color: Colors.white,
                                        fontWeight: FontWeight.w900))),
                            Text('₹${cartTotal.toStringAsFixed(0)}',
                                style: const TextStyle(
                                    color: Colors.white,
                                    fontWeight: FontWeight.w900))
                          ]),
                          const SizedBox(height: 7),
                          FilledButton(
                              onPressed: showCart,
                              style: FilledButton.styleFrom(
                                  backgroundColor: Colors.white,
                                  foregroundColor: TablePlayColors.deep,
                                  minimumSize: const Size(0, 40)),
                              child: const Text('Review order'))
                        ])),
              Container(
                  margin: const EdgeInsets.fromLTRB(12, 0, 12, 8),
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                      color: Colors.black.withValues(alpha: .12),
                      borderRadius: BorderRadius.circular(14)),
                  child: Row(children: [
                    Icon(
                        error == null
                            ? Icons.wifi_rounded
                            : Icons.wifi_off_rounded,
                        color: error == null
                            ? TablePlayColors.gold
                            : Colors.redAccent,
                        size: 19),
                    const SizedBox(width: 9),
                    Expanded(
                        child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                          Text(
                              error == null
                                  ? 'Restaurant network online'
                                  : 'Connection issue',
                              style: const TextStyle(
                                  color: Colors.white,
                                  fontSize: 10.5,
                                  fontWeight: FontWeight.w800)),
                          Text(
                              Uri.tryParse(widget.store.baseUrl)?.host ??
                                  widget.store.baseUrl,
                              overflow: TextOverflow.ellipsis,
                              style: const TextStyle(
                                  color: Colors.white54, fontSize: 9))
                        ]))
                  ])),
              Padding(
                  padding: const EdgeInsets.fromLTRB(10, 0, 10, 2),
                  child: TextButton.icon(
                      onPressed: () => LocalUpdateGate.check(context),
                      icon: const Icon(Icons.system_update_rounded),
                      label: const Text('Check for app updates'),
                      style: TextButton.styleFrom(
                          foregroundColor: Colors.white70))),
              Padding(
                  padding: const EdgeInsets.fromLTRB(10, 0, 10, 12),
                  child: TextButton.icon(
                      onPressed: requestReset,
                      icon: const Icon(Icons.settings_outlined),
                      label: const Text('Tablet pairing settings'),
                      style: TextButton.styleFrom(
                          foregroundColor: Colors.white70)))
            ])),
      );

  Widget? _destinationBadge(int index) {
    if (index == 0 && cart.isNotEmpty) {
      return Badge(label: Text('$cartCount'));
    }
    if (index == 1 && orders.isNotEmpty) {
      return Text('${orders.length}',
          style: const TextStyle(
              color: TablePlayColors.gold, fontWeight: FontWeight.w900));
    }
    if (index == 2 && game['unlocked'] != true) {
      return const Icon(Icons.lock_rounded, color: Colors.white38, size: 17);
    }
    return null;
  }

  Widget startVisit() => Container(
        width: double.infinity,
        decoration: const BoxDecoration(
            gradient: LinearGradient(
                colors: [Color(0xffeef4f0), TablePlayColors.canvas],
                begin: Alignment.topCenter,
                end: Alignment.bottomCenter)),
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
                            boxShadow: [
                              BoxShadow(
                                  color: TablePlayColors.deep
                                      .withValues(alpha: .08),
                                  blurRadius: 28,
                                  offset: const Offset(0, 12))
                            ]),
                        child:
                            Column(mainAxisSize: MainAxisSize.min, children: [
                          Container(
                              width: 72,
                              height: 72,
                              decoration: BoxDecoration(
                                  color: TablePlayColors.accent
                                      .withValues(alpha: .12),
                                  borderRadius: BorderRadius.circular(22)),
                              child: const Icon(Icons.waving_hand_rounded,
                                  size: 37, color: TablePlayColors.accent)),
                          const SizedBox(height: 20),
                          Text('Welcome to ${widget.store.tableCode}',
                              style: Theme.of(context)
                                  .textTheme
                                  .headlineMedium
                                  ?.copyWith(
                                      fontWeight: FontWeight.w900,
                                      letterSpacing: -.8)),
                          const SizedBox(height: 8),
                          const Text(
                              'Tell us your party size to start browsing the menu.',
                              textAlign: TextAlign.center,
                              style: TextStyle(color: TablePlayColors.muted)),
                          const SizedBox(height: 24),
                          Container(
                              padding: const EdgeInsets.all(8),
                              decoration: BoxDecoration(
                                  color: TablePlayColors.canvas,
                                  borderRadius: BorderRadius.circular(16)),
                              child: Row(
                                  mainAxisSize: MainAxisSize.min,
                                  children: [
                                    IconButton(
                                        onPressed: guestCount > 1
                                            ? () => setState(() => guestCount--)
                                            : null,
                                        icon: const Icon(
                                            Icons.remove_circle_outline)),
                                    Padding(
                                        padding: const EdgeInsets.symmetric(
                                            horizontal: 18),
                                        child: Column(children: [
                                          Text('$guestCount',
                                              style: const TextStyle(
                                                  fontSize: 27,
                                                  fontWeight: FontWeight.w900)),
                                          const Text('GUESTS',
                                              style: TextStyle(
                                                  fontSize: 9,
                                                  color: TablePlayColors.muted,
                                                  fontWeight: FontWeight.w800,
                                                  letterSpacing: 1))
                                        ])),
                                    IconButton(
                                        onPressed: guestCount < 20
                                            ? () => setState(() => guestCount++)
                                            : null,
                                        icon: const Icon(
                                            Icons.add_circle_outline))
                                  ])),
                          const SizedBox(height: 24),
                          FilledButton.icon(
                              onPressed: openingVisit ? null : openVisit,
                              icon: openingVisit
                                  ? const SizedBox.square(
                                      dimension: 18,
                                      child: CircularProgressIndicator(
                                          strokeWidth: 2, color: Colors.white))
                                  : const Icon(Icons.arrow_forward_rounded),
                              label: Text(openingVisit
                                  ? 'Opening your table…'
                                  : 'Start table visit'),
                              style: FilledButton.styleFrom(
                                  minimumSize: const Size.fromHeight(52),
                                  backgroundColor: TablePlayColors.accent)),
                        ]))))),
      );

  Widget menuPage() => CustomerMenuView(
        categories: categories,
        cartCount: cartCount,
        cartTotal: cartTotal,
        onOpenCart: showCart,
        onAdd: (item, instruction) => add(item, instruction),
      );

  Widget legacyMenuPage() => Column(children: [
        Container(
            color: Colors.white,
            padding: const EdgeInsets.fromLTRB(20, 18, 20, 15),
            child: Row(children: [
              Expanded(
                  child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                    Text('What would you like?',
                        style: Theme.of(context)
                            .textTheme
                            .headlineSmall
                            ?.copyWith(
                                fontWeight: FontWeight.w900,
                                letterSpacing: -.5)),
                    const SizedBox(height: 3),
                    const Text('Freshly prepared for your table',
                        style: TextStyle(
                            color: TablePlayColors.muted, fontSize: 12))
                  ])),
              if (cart.isNotEmpty)
                FilledButton.icon(
                    onPressed: showCart,
                    icon: const Icon(Icons.shopping_bag_outlined, size: 19),
                    label:
                        Text('$cartCount · ₹${cartTotal.toStringAsFixed(0)}'),
                    style: FilledButton.styleFrom(
                        backgroundColor: TablePlayColors.accent))
            ])),
        Expanded(
            child: ListView.builder(
                padding: const EdgeInsets.fromLTRB(16, 14, 16, 95),
                itemCount: categories.length,
                itemBuilder: (context, index) {
                  final category = Map<String, dynamic>.from(categories[index]);
                  final items = category['menu_items'] as List;
                  return Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Padding(
                            padding: const EdgeInsets.fromLTRB(3, 12, 3, 10),
                            child: Row(children: [
                              Expanded(
                                  child: Column(
                                      crossAxisAlignment:
                                          CrossAxisAlignment.start,
                                      children: [
                                    Text(category['name'],
                                        style: const TextStyle(
                                            fontSize: 18,
                                            fontWeight: FontWeight.w900)),
                                    if (category['description'] != null)
                                      Text(category['description'],
                                          style: const TextStyle(
                                              color: TablePlayColors.muted,
                                              fontSize: 11))
                                  ])),
                              Text('${items.length} ITEMS',
                                  style: const TextStyle(
                                      color: TablePlayColors.muted,
                                      fontSize: 9,
                                      fontWeight: FontWeight.w800,
                                      letterSpacing: 1))
                            ])),
                        ...items.map((raw) {
                          final item = Map<String, dynamic>.from(raw);
                          final veg = item['food_type'] == 'veg';
                          return Container(
                              margin: const EdgeInsets.only(bottom: 10),
                              padding: const EdgeInsets.all(15),
                              decoration: BoxDecoration(
                                  color: Colors.white,
                                  borderRadius: BorderRadius.circular(17),
                                  border: Border.all(
                                      color: TablePlayColors.border)),
                              child: Row(children: [
                                Container(
                                    width: 48,
                                    height: 48,
                                    decoration: BoxDecoration(
                                        color: (veg
                                                ? TablePlayColors.success
                                                : TablePlayColors.accent)
                                            .withValues(alpha: .1),
                                        borderRadius:
                                            BorderRadius.circular(14)),
                                    child: Icon(
                                        veg
                                            ? Icons.eco_outlined
                                            : Icons.restaurant_rounded,
                                        color: veg
                                            ? TablePlayColors.success
                                            : TablePlayColors.accent)),
                                const SizedBox(width: 13),
                                Expanded(
                                    child: Column(
                                        crossAxisAlignment:
                                            CrossAxisAlignment.start,
                                        children: [
                                      Row(children: [
                                        Container(
                                            width: 9,
                                            height: 9,
                                            decoration: BoxDecoration(
                                                shape: BoxShape.circle,
                                                border: Border.all(
                                                    color: veg
                                                        ? TablePlayColors
                                                            .success
                                                        : TablePlayColors
                                                            .accent,
                                                    width: 2))),
                                        const SizedBox(width: 6),
                                        Expanded(
                                            child: Text(item['name'],
                                                style: const TextStyle(
                                                    fontWeight: FontWeight.w900,
                                                    fontSize: 14)))
                                      ]),
                                      const SizedBox(height: 4),
                                      Text(
                                          item['description'] ??
                                              item['food_type']
                                                  .toString()
                                                  .replaceAll('_', ' '),
                                          maxLines: 2,
                                          overflow: TextOverflow.ellipsis,
                                          style: const TextStyle(
                                              color: TablePlayColors.muted,
                                              fontSize: 11,
                                              height: 1.35)),
                                      if (item['preparation_minutes'] != null)
                                        Padding(
                                            padding:
                                                const EdgeInsets.only(top: 5),
                                            child: Text(
                                                '${item['preparation_minutes']} min preparation',
                                                style: const TextStyle(
                                                    color:
                                                        TablePlayColors.muted,
                                                    fontSize: 9.5)))
                                    ])),
                                const SizedBox(width: 10),
                                Column(
                                    crossAxisAlignment: CrossAxisAlignment.end,
                                    children: [
                                      Text('₹${item['price']}',
                                          style: const TextStyle(
                                              fontWeight: FontWeight.w900,
                                              fontSize: 15)),
                                      const SizedBox(height: 7),
                                      FilledButton(
                                          onPressed: () => add(item),
                                          style: FilledButton.styleFrom(
                                              minimumSize: const Size(76, 36),
                                              padding:
                                                  const EdgeInsets.symmetric(
                                                      horizontal: 13,
                                                      vertical: 8),
                                              backgroundColor:
                                                  TablePlayColors.accent),
                                          child: const Text('Add'))
                                    ]),
                              ]));
                        }),
                      ]);
                })),
        if (cart.isNotEmpty)
          SafeArea(
              top: false,
              child: Container(
                  color: Colors.white,
                  padding: const EdgeInsets.fromLTRB(14, 10, 14, 12),
                  child: FilledButton.icon(
                      onPressed: showCart,
                      icon: const Icon(Icons.shopping_cart_checkout_rounded),
                      label: Text(
                          'Review $cartCount item${cartCount == 1 ? '' : 's'} · ₹${cartTotal.toStringAsFixed(2)}'),
                      style: FilledButton.styleFrom(
                          minimumSize: const Size.fromHeight(52),
                          backgroundColor: TablePlayColors.accent)))),
      ]);

  Widget ordersPage() => RefreshIndicator(
      onRefresh: refreshState,
      child: ListView(padding: const EdgeInsets.all(16), children: [
        Text('Your orders',
            style: Theme.of(context)
                .textTheme
                .headlineSmall
                ?.copyWith(fontWeight: FontWeight.w900)),
        const SizedBox(height: 4),
        const Text('Pull down to refresh kitchen progress',
            style: TextStyle(color: TablePlayColors.muted, fontSize: 12)),
        const SizedBox(height: 16),
        if (orders.isEmpty)
          _emptyPanel(Icons.receipt_long_outlined, 'No orders yet',
              'Items you submit will appear here with live progress.'),
        ...orders.map((raw) {
          final order = Map<String, dynamic>.from(raw);
          final status = order['status'].toString();
          final steps = [
            'pending',
            'confirmed',
            'preparing',
            'ready',
            'served'
          ];
          final current = steps.indexOf(status);
          return Container(
              margin: const EdgeInsets.only(bottom: 13),
              decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(18),
                  border: Border.all(color: TablePlayColors.border)),
              child: ExpansionTile(
                  shape: const Border(),
                  collapsedShape: const Border(),
                  tilePadding:
                      const EdgeInsets.symmetric(horizontal: 17, vertical: 5),
                  childrenPadding: const EdgeInsets.fromLTRB(17, 0, 17, 15),
                  leading: Container(
                      width: 43,
                      height: 43,
                      decoration: BoxDecoration(
                          color: _statusColor(status).withValues(alpha: .12),
                          borderRadius: BorderRadius.circular(13)),
                      child: Icon(_statusIcon(status),
                          color: _statusColor(status))),
                  title: Text(order['order_number'],
                      style: const TextStyle(fontWeight: FontWeight.w900)),
                  subtitle: Text(status.toUpperCase(),
                      style: TextStyle(
                          color: _statusColor(status),
                          fontSize: 10,
                          fontWeight: FontWeight.w900,
                          letterSpacing: .6)),
                  children: [
                    Row(
                        children: List.generate(
                            steps.length,
                            (index) => Expanded(
                                    child: Row(children: [
                                  Expanded(
                                      child: Container(
                                          height: 4,
                                          decoration: BoxDecoration(
                                              color: index <= current
                                                  ? _statusColor(status)
                                                  : TablePlayColors.border,
                                              borderRadius:
                                                  BorderRadius.circular(99)))),
                                  if (index < steps.length - 1)
                                    const SizedBox(width: 3)
                                ])))),
                    const SizedBox(height: 15),
                    ...(order['items'] as List).map<Widget>((item) => Padding(
                        padding: const EdgeInsets.symmetric(vertical: 5),
                        child: Row(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text('${item['quantity']}×',
                                  style: const TextStyle(
                                      color: TablePlayColors.accent,
                                      fontWeight: FontWeight.w900)),
                              const SizedBox(width: 9),
                              Expanded(
                                  child: Column(
                                      crossAxisAlignment:
                                          CrossAxisAlignment.start,
                                      children: [
                                    Text(item['item_name_snapshot'],
                                        style: const TextStyle(
                                            fontWeight: FontWeight.w700)),
                                    if (item['special_instruction'] != null)
                                      Text(item['special_instruction'],
                                          style: const TextStyle(
                                              color: TablePlayColors.muted,
                                              fontSize: 10))
                                  ]))
                            ]))),
                  ]));
        }),
      ]));

  Widget servicesPage() =>
      ListView(padding: const EdgeInsets.all(20), children: [
        Text('How can we help?',
            style: Theme.of(context)
                .textTheme
                .headlineSmall
                ?.copyWith(fontWeight: FontWeight.w900)),
        const SizedBox(height: 5),
        const Text('Tap once and our team will be notified immediately.',
            style: TextStyle(color: TablePlayColors.muted)),
        const SizedBox(height: 22),
        _serviceCard(
            Icons.support_agent_rounded,
            'Call a waiter',
            'Need assistance at your table',
            'call_waiter',
            TablePlayColors.deep),
        _serviceCard(
            Icons.water_drop_rounded,
            'Request water',
            'We’ll bring water to your table',
            'water',
            const Color(0xff346b99)),
        _serviceCard(
            Icons.receipt_long_rounded,
            'Request the bill',
            'Let the counter know you are ready',
            'bill',
            TablePlayColors.accent),
        const SizedBox(height: 14),
        Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
                color: TablePlayColors.deep.withValues(alpha: .06),
                borderRadius: BorderRadius.circular(16)),
            child: const Row(children: [
              Icon(Icons.info_outline_rounded, color: TablePlayColors.deep),
              SizedBox(width: 12),
              Expanded(
                  child: Text(
                      'Your table number is attached automatically. There is no need to leave this screen open.',
                      style: TextStyle(
                          color: TablePlayColors.muted,
                          fontSize: 11,
                          height: 1.4)))
            ])),
      ]);

  Widget _serviceCard(IconData icon, String title, String subtitle, String type,
          Color color) =>
      InkWell(
          onTap: () => requestService(type, title),
          borderRadius: BorderRadius.circular(18),
          child: Container(
              margin: const EdgeInsets.only(bottom: 12),
              padding: const EdgeInsets.all(17),
              decoration: BoxDecoration(
                  color: Colors.white,
                  borderRadius: BorderRadius.circular(18),
                  border: Border.all(color: TablePlayColors.border)),
              child: Row(children: [
                Container(
                    width: 52,
                    height: 52,
                    decoration: BoxDecoration(
                        color: color.withValues(alpha: .1),
                        borderRadius: BorderRadius.circular(15)),
                    child: Icon(icon, color: color, size: 27)),
                const SizedBox(width: 14),
                Expanded(
                    child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                      Text(title,
                          style: const TextStyle(
                              fontWeight: FontWeight.w900, fontSize: 14)),
                      const SizedBox(height: 3),
                      Text(subtitle,
                          style: const TextStyle(
                              color: TablePlayColors.muted, fontSize: 11))
                    ])),
                const Icon(Icons.arrow_forward_ios_rounded,
                    size: 15, color: TablePlayColors.muted)
              ])));

  Widget _emptyPanel(IconData icon, String title, String subtitle) => Container(
      padding: const EdgeInsets.all(36),
      decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(18),
          border: Border.all(color: TablePlayColors.border)),
      child: Column(children: [
        Icon(icon, size: 46, color: TablePlayColors.accent),
        const SizedBox(height: 12),
        Text(title,
            style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 16)),
        const SizedBox(height: 5),
        Text(subtitle,
            textAlign: TextAlign.center,
            style: const TextStyle(color: TablePlayColors.muted, fontSize: 11))
      ]));

  Color _statusColor(String status) => switch (status) {
        'ready' || 'served' => TablePlayColors.success,
        'rejected' || 'cancelled' => const Color(0xffc34836),
        'preparing' => const Color(0xff346b99),
        _ => TablePlayColors.accent
      };
  IconData _statusIcon(String status) => switch (status) {
        'ready' => Icons.notifications_active_rounded,
        'served' => Icons.check_circle_rounded,
        'preparing' => Icons.soup_kitchen_rounded,
        'rejected' || 'cancelled' => Icons.cancel_rounded,
        _ => Icons.schedule_rounded
      };
}
