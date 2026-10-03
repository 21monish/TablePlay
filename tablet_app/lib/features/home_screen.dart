import 'dart:async';
import 'package:flutter/material.dart';
import '../core/api_client.dart';
import '../core/device_store.dart';
import 'game_center.dart';

class CartLine {
  CartLine(this.item, {this.quantity = 1});
  final Map<String, dynamic> item;
  int quantity;
  String instruction = '';
  double get total =>
      (item['price'] is num
          ? item['price'].toDouble()
          : double.parse(item['price'].toString())) *
      quantity;
}

class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key, required this.store, required this.onReset});
  final DeviceStore store;
  final VoidCallback onReset;
  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  late final ApiClient api;
  Timer? polling;
  int tab = 0;
  bool loading = true;
  String? error;
  Map<String, dynamic>? session;
  List<dynamic> categories = [], orders = [];
  Map<int, CartLine> cart = {};
  Map<String, dynamic> game = {'unlocked': false, 'remaining_seconds': 0};
  @override
  void initState() {
    super.initState();
    api = ApiClient(baseUrl: widget.store.baseUrl)
      ..deviceUuid = widget.store.uuid
      ..deviceToken = widget.store.token;
    loadAll();
    polling = Timer.periodic(const Duration(seconds: 4), (_) => refreshState());
  }

  @override
  void dispose() {
    polling?.cancel();
    super.dispose();
  }

  Future<void> loadAll() async {
    try {
      categories = await api.get('/menu');
      await refreshState();
    } catch (e) {
      error = e.toString();
    } finally {
      if (mounted) setState(() => loading = false);
    }
  }

  Future<void> refreshState() async {
    try {
      session = Map<String, dynamic>.from(await api.get('/table/session'));
      orders = await api.get('/table/orders');
      game = Map<String, dynamic>.from(await api.get('/games/access'));
      error = null;
    } on ApiException catch (e) {
      if (e.status == 404) {
        session = null;
      } else {
        error = e.toString();
      }
    } catch (e) {
      error = e.toString();
    }
    if (mounted) {
      setState(() {});
    }
  }

  Future<void> openVisit() async {
    try {
      session = Map<String, dynamic>.from(
          await api.post('/table-sessions', {'guest_count': 2}));
      await refreshState();
    } catch (e) {
      setState(() => error = e.toString());
    }
  }

  void add(Map<String, dynamic> item) {
    setState(() => cart.update(item['id'], (line) {
          line.quantity++;
          return line;
        }, ifAbsent: () => CartLine(item)));
  }

  Future<void> showCart() async {
    await showModalBottomSheet<void>(
        context: context,
        isScrollControlled: true,
        builder: (sheetContext) => StatefulBuilder(builder: (_, setSheet) {
              return SafeArea(
                  child: Padding(
                      padding: EdgeInsets.fromLTRB(20, 20, 20,
                          MediaQuery.viewInsetsOf(sheetContext).bottom + 20),
                      child: Column(mainAxisSize: MainAxisSize.min, children: [
                        Text('Your cart',
                            style: Theme.of(context).textTheme.headlineSmall),
                        const SizedBox(height: 12),
                        ...cart.values.map((line) => ListTile(
                            title: Text(line.item['name']),
                            subtitle: TextFormField(
                                initialValue: line.instruction,
                                decoration: const InputDecoration(
                                    hintText: 'Special instruction (optional)'),
                                onChanged: (value) => line.instruction = value),
                            trailing:
                                Row(mainAxisSize: MainAxisSize.min, children: [
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
                                  icon:
                                      const Icon(Icons.remove_circle_outline)),
                              Text('${line.quantity}'),
                              IconButton(
                                  onPressed: () {
                                    setState(() => line.quantity++);
                                    setSheet(() {});
                                  },
                                  icon: const Icon(Icons.add_circle_outline))
                            ]))),
                        const SizedBox(height: 12),
                        SizedBox(
                            width: double.infinity,
                            child: FilledButton(
                                onPressed: cart.isEmpty
                                    ? null
                                    : () {
                                        Navigator.pop(sheetContext);
                                        checkout();
                                      },
                                child: Text(
                                    'Place order · ₹${cart.values.fold<double>(0, (s, l) => s + l.total).toStringAsFixed(2)}')))
                      ])));
            }));
  }

  Future<void> checkout() async {
    if (cart.isEmpty) {
      return;
    }
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
            .toList()
      });
      cart.clear();
      tab = 1;
      await refreshState();
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Order sent to the counter.')));
      }
    } catch (e) {
      setState(() => error = e.toString());
    }
  }

  Future<void> service(String type) async {
    try {
      await api.post('/service-requests', {'request_type': type});
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text('${type.replaceAll('_', ' ')} requested.')));
      }
    } catch (e) {
      setState(() => error = e.toString());
    }
  }

  Future<void> reset() async {
    await widget.store.clear();
    widget.onReset();
  }

  @override
  Widget build(BuildContext context) {
    if (loading) {
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }
    final pages = [menu(), orderList(), games(), services()];
    return Scaffold(
        appBar: AppBar(
            title: Text('TablePlay · ${widget.store.tableCode}'),
            actions: [
              IconButton(
                  onPressed: refreshState, icon: const Icon(Icons.refresh)),
              PopupMenuButton<String>(
                  itemBuilder: (_) => [
                        const PopupMenuItem(
                            value: 'reset', child: Text('Reset tablet pairing'))
                      ],
                  onSelected: (v) {
                    if (v == 'reset') reset();
                  })
            ]),
        body: Column(children: [
          if (error != null)
            MaterialBanner(content: Text(error!), actions: [
              TextButton(onPressed: refreshState, child: const Text('Retry'))
            ]),
          if (session == null) startVisit(),
          if (session != null) Expanded(child: pages[tab])
        ]),
        bottomNavigationBar: session == null
            ? null
            : NavigationBar(
                selectedIndex: tab,
                onDestinationSelected: (v) => setState(() => tab = v),
                destinations: [
                    NavigationDestination(
                        icon: Badge(
                            isLabelVisible: cart.isNotEmpty,
                            label: Text(
                                '${cart.values.fold<int>(0, (s, l) => s + l.quantity)}'),
                            child: const Icon(Icons.menu_book)),
                        label: 'Menu'),
                    const NavigationDestination(
                        icon: Icon(Icons.receipt_long), label: 'Orders'),
                    NavigationDestination(
                        icon: Icon(game['unlocked'] == true
                            ? Icons.sports_esports
                            : Icons.lock),
                        label: 'Games'),
                    const NavigationDestination(
                        icon: Icon(Icons.room_service), label: 'Service')
                  ]));
  }

  Widget startVisit() => Expanded(
      child: Center(
          child: Card(
              child: Padding(
                  padding: const EdgeInsets.all(28),
                  child: Column(mainAxisSize: MainAxisSize.min, children: [
                    const Icon(Icons.waving_hand, size: 56),
                    const SizedBox(height: 12),
                    Text('Welcome to TablePlay',
                        style: Theme.of(context).textTheme.headlineSmall),
                    const SizedBox(height: 8),
                    const Text('Start your table visit to browse and order.'),
                    const SizedBox(height: 18),
                    FilledButton(
                        onPressed: openVisit, child: const Text('Start visit'))
                  ])))));
  Widget menu() => Column(children: [
        Expanded(
            child: ListView(
                children: categories
                    .map((category) => ExpansionTile(
                        initiallyExpanded: true,
                        title: Text(category['name'],
                            style:
                                const TextStyle(fontWeight: FontWeight.bold)),
                        children: (category['menu_items'] as List).map((raw) {
                          final item = Map<String, dynamic>.from(raw);
                          return ListTile(
                              title: Text(item['name']),
                              subtitle: Text(
                                  item['description'] ?? item['food_type']),
                              trailing: FilledButton.tonal(
                                  onPressed: () => add(item),
                                  child: Text('₹${item['price']} · Add')));
                        }).toList()))
                    .toList())),
        if (cart.isNotEmpty)
          SafeArea(
              child: Padding(
                  padding: const EdgeInsets.all(12),
                  child: FilledButton.icon(
                      onPressed: showCart,
                      icon: const Icon(Icons.shopping_cart_checkout),
                      label: Text(
                          'Review cart · ₹${cart.values.fold<double>(0, (s, l) => s + l.total).toStringAsFixed(2)}'))))
      ]);
  Widget orderList() => RefreshIndicator(
      onRefresh: refreshState,
      child: ListView(
          padding: const EdgeInsets.all(12),
          children: orders.isEmpty
              ? [
                  const Card(
                      child: ListTile(
                          title: Text('No orders yet'),
                          subtitle:
                              Text('Your submitted orders will appear here.')))
                ]
              : orders
                  .map((order) => Card(
                      child: ExpansionTile(
                          title: Text(order['order_number']),
                          subtitle:
                              Text((order['status'] as String).toUpperCase()),
                          children: (order['items'] as List)
                              .map<Widget>((i) => ListTile(
                                  title: Text(
                                      '${i['quantity']} × ${i['item_name_snapshot']}'),
                                  subtitle: i['special_instruction'] != null
                                      ? Text(i['special_instruction'])
                                      : null))
                              .toList())))
                  .toList()));
  Widget games() => GameCenter(api: api, access: game);

  Widget services() => Center(
          child: Wrap(spacing: 14, runSpacing: 14, children: [
        serviceButton(Icons.support_agent, 'Call waiter', 'call_waiter'),
        serviceButton(Icons.water_drop, 'Request water', 'water'),
        serviceButton(Icons.receipt, 'Request bill', 'bill')
      ]));
  Widget serviceButton(IconData icon, String label, String type) =>
      FilledButton.tonalIcon(
          onPressed: () => service(type),
          icon: Icon(icon),
          label: Padding(
              padding: const EdgeInsets.symmetric(vertical: 18),
              child: Text(label)));
}
