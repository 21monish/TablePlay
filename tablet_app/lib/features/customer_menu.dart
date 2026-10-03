import 'package:flutter/material.dart';

import '../theme/tableplay_theme.dart';

typedef AddMenuItem = void Function(
    Map<String, dynamic> item, String instruction);

class CustomerMenuView extends StatefulWidget {
  const CustomerMenuView({
    super.key,
    required this.categories,
    required this.cartCount,
    required this.cartTotal,
    required this.onOpenCart,
    required this.onAdd,
  });

  final List<dynamic> categories;
  final int cartCount;
  final double cartTotal;
  final VoidCallback onOpenCart;
  final AddMenuItem onAdd;

  @override
  State<CustomerMenuView> createState() => _CustomerMenuViewState();
}

class _CustomerMenuViewState extends State<CustomerMenuView> {
  final search = TextEditingController();
  String query = '';
  String filter = 'all';
  int? categoryId;

  @override
  void dispose() {
    search.dispose();
    super.dispose();
  }

  List<Map<String, dynamic>> get items {
    final result = <Map<String, dynamic>>[];
    for (final rawCategory in widget.categories) {
      final category = Map<String, dynamic>.from(rawCategory);
      if (categoryId != null && category['id'] != categoryId) continue;
      for (final rawItem in category['menu_items'] as List? ?? []) {
        final item = Map<String, dynamic>.from(rawItem);
        item['_category_name'] = category['name'];
        final haystack =
            '${item['name']} ${item['description']} ${item['ingredients']}'
                .toLowerCase();
        if (query.isNotEmpty && !haystack.contains(query.toLowerCase())) {
          continue;
        }
        if (filter == 'veg' && item['food_type'] != 'veg') continue;
        if (filter == 'non_veg' && item['food_type'] == 'veg') continue;
        if (filter == 'recommended' &&
            item['is_recommended'] != true &&
            item['is_bestseller'] != true) {
          continue;
        }
        result.add(item);
      }
    }
    return result;
  }

  @override
  Widget build(BuildContext context) => Column(children: [
        Container(
          color: Colors.white,
          padding: const EdgeInsets.fromLTRB(18, 16, 18, 13),
          child: Column(children: [
            Row(children: [
              Expanded(
                  child: Text('Explore our menu',
                      style: Theme.of(context)
                          .textTheme
                          .headlineSmall
                          ?.copyWith(fontWeight: FontWeight.w900))),
              if (widget.cartCount > 0)
                FilledButton.icon(
                    onPressed: widget.onOpenCart,
                    icon: const Icon(Icons.shopping_bag_outlined),
                    label: Text(
                        '${widget.cartCount} · ₹${widget.cartTotal.toStringAsFixed(0)}')),
            ]),
            const SizedBox(height: 12),
            TextField(
              controller: search,
              onChanged: (value) => setState(() => query = value.trim()),
              decoration: const InputDecoration(
                  prefixIcon: Icon(Icons.search_rounded),
                  hintText: 'Search dishes, ingredients, or flavours',
                  suffixIcon: Icon(Icons.tune_rounded)),
            ),
            const SizedBox(height: 10),
            SizedBox(
                height: 38,
                child: ListView(scrollDirection: Axis.horizontal, children: [
                  _filterChip('all', 'All'),
                  _filterChip('recommended', 'Popular'),
                  _filterChip('veg', 'Vegetarian'),
                  _filterChip('non_veg', 'Non-veg'),
                ])),
            const SizedBox(height: 8),
            SizedBox(
                height: 38,
                child: ListView(scrollDirection: Axis.horizontal, children: [
                  ChoiceChip(
                      label: const Text('Every category'),
                      selected: categoryId == null,
                      onSelected: (_) => setState(() => categoryId = null)),
                  const SizedBox(width: 7),
                  ...widget.categories.map((raw) {
                    final category = Map<String, dynamic>.from(raw);
                    return Padding(
                        padding: const EdgeInsets.only(right: 7),
                        child: ChoiceChip(
                            label: Text('${category['name']}'),
                            selected: categoryId == category['id'],
                            onSelected: (_) => setState(
                                () => categoryId = category['id'] as int)));
                  }),
                ])),
            const SizedBox(height: 10),
            Row(children: [
              const Icon(Icons.restaurant_menu_rounded,
                  size: 15, color: TablePlayColors.muted),
              const SizedBox(width: 6),
              Text(
                  '${items.length} dish${items.length == 1 ? '' : 'es'} available',
                  style: const TextStyle(
                      color: TablePlayColors.muted,
                      fontSize: 10.5,
                      fontWeight: FontWeight.w700)),
              const Spacer(),
              if (query.isNotEmpty || filter != 'all' || categoryId != null)
                TextButton.icon(
                  onPressed: () => setState(() {
                    search.clear();
                    query = '';
                    filter = 'all';
                    categoryId = null;
                  }),
                  icon: const Icon(Icons.filter_alt_off_outlined, size: 16),
                  label: const Text('Clear filters'),
                ),
            ]),
          ]),
        ),
        Expanded(child: LayoutBuilder(builder: (context, constraints) {
          final columns = constraints.maxWidth >= 1050
              ? 3
              : (constraints.maxWidth >= 620 ? 2 : 1);
          if (items.isEmpty) return const _MenuEmpty();
          return GridView.builder(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 100),
            gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
                crossAxisCount: columns,
                mainAxisExtent: 286,
                crossAxisSpacing: 14,
                mainAxisSpacing: 14),
            itemCount: items.length,
            itemBuilder: (_, index) => _card(items[index]),
          );
        })),
        if (widget.cartCount > 0)
          SafeArea(
              top: false,
              child: Container(
                  color: Colors.white,
                  padding: const EdgeInsets.fromLTRB(14, 10, 14, 12),
                  child: FilledButton.icon(
                    onPressed: widget.onOpenCart,
                    icon: const Icon(Icons.shopping_cart_checkout_rounded),
                    label: Text(
                        'Review ${widget.cartCount} item${widget.cartCount == 1 ? '' : 's'} · ₹${widget.cartTotal.toStringAsFixed(2)}'),
                    style: FilledButton.styleFrom(
                        minimumSize: const Size.fromHeight(52),
                        backgroundColor: TablePlayColors.accent),
                  ))),
      ]);

  Widget _filterChip(String value, String label) => Padding(
        padding: const EdgeInsets.only(right: 7),
        child: ChoiceChip(
            label: Text(label),
            selected: filter == value,
            onSelected: (_) => setState(() => filter = value)),
      );

  Widget _card(Map<String, dynamic> item) {
    final veg = item['food_type'] == 'veg';
    final offer = item['discount_price'] != null;
    final price =
        item['effective_price'] ?? item['discount_price'] ?? item['price'];
    return Material(
      color: Colors.white,
      borderRadius: BorderRadius.circular(20),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
          onTap: () => _details(item),
          child:
              Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Stack(children: [
              _FoodImage(url: item['image_url']?.toString(), height: 132),
              Positioned(
                  top: 9,
                  left: 9,
                  child: Wrap(spacing: 5, children: [
                    if (item['is_bestseller'] == true)
                      const _Badge('BESTSELLER', TablePlayColors.accent),
                    if (item['is_recommended'] == true)
                      const _Badge('CHEF PICK', TablePlayColors.deep),
                  ])),
              if (offer)
                const Positioned(
                    top: 9,
                    right: 9,
                    child: _Badge('SPECIAL PRICE', TablePlayColors.success)),
            ]),
            Expanded(
                child: Padding(
                    padding: const EdgeInsets.all(13),
                    child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text('${item['_category_name'] ?? ''}'.toUpperCase(),
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: const TextStyle(
                                  color: TablePlayColors.muted,
                                  fontSize: 8.5,
                                  fontWeight: FontWeight.w900,
                                  letterSpacing: .75)),
                          const SizedBox(height: 4),
                          Row(children: [
                            Container(
                                width: 10,
                                height: 10,
                                decoration: BoxDecoration(
                                    border: Border.all(
                                        color: veg
                                            ? TablePlayColors.success
                                            : TablePlayColors.accent,
                                        width: 2))),
                            const SizedBox(width: 7),
                            Expanded(
                                child: Text('${item['name']}',
                                    maxLines: 1,
                                    overflow: TextOverflow.ellipsis,
                                    style: const TextStyle(
                                        fontSize: 15,
                                        fontWeight: FontWeight.w900)))
                          ]),
                          const SizedBox(height: 5),
                          Text(
                              '${item['short_description'] ?? item['description'] ?? item['_category_name']}',
                              maxLines: 2,
                              overflow: TextOverflow.ellipsis,
                              style: const TextStyle(
                                  color: TablePlayColors.muted,
                                  fontSize: 10.5,
                                  height: 1.3)),
                          const SizedBox(height: 7),
                          Wrap(spacing: 5, runSpacing: 4, children: [
                            if (item['preparation_minutes'] != null)
                              _MiniInfo(Icons.schedule_rounded,
                                  '${item['preparation_minutes']} min'),
                            if (item['calories'] != null)
                              _MiniInfo(Icons.local_fire_department_outlined,
                                  '${item['calories']} kcal'),
                            if (item['spice_level'] != null &&
                                item['spice_level'] != 'none')
                              _MiniInfo(Icons.whatshot_rounded,
                                  '${item['spice_level']}'),
                          ]),
                          const Spacer(),
                          Row(children: [
                            Text('₹$price',
                                style: const TextStyle(
                                    fontSize: 16, fontWeight: FontWeight.w900)),
                            if (offer) ...[
                              const SizedBox(width: 6),
                              Text('₹${item['price']}',
                                  style: const TextStyle(
                                      color: TablePlayColors.muted,
                                      decoration: TextDecoration.lineThrough,
                                      fontSize: 11))
                            ],
                            const Spacer(),
                            FilledButton(
                                onPressed: () => widget.onAdd(item, ''),
                                style: FilledButton.styleFrom(
                                    minimumSize: const Size(66, 36),
                                    padding: const EdgeInsets.symmetric(
                                        horizontal: 12)),
                                child: const Text('Add')),
                          ]),
                        ]))),
          ])),
    );
  }

  Future<void> _details(Map<String, dynamic> item) async {
    final notes = TextEditingController();
    final selected = <String, String>{};
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      backgroundColor: Colors.transparent,
      builder: (sheetContext) => StatefulBuilder(builder: (_, setSheet) {
        final options = item['customizations'] as List? ?? [];
        return DraggableScrollableSheet(
            initialChildSize: .88,
            maxChildSize: .96,
            minChildSize: .62,
            expand: false,
            builder: (_, controller) => Material(
                color: Colors.white,
                borderRadius:
                    const BorderRadius.vertical(top: Radius.circular(28)),
                clipBehavior: Clip.antiAlias,
                child: ListView(
                    controller: controller,
                    padding: EdgeInsets.zero,
                    children: [
                      _FoodImage(
                          url: item['image_url']?.toString(), height: 245),
                      Padding(
                          padding: const EdgeInsets.fromLTRB(22, 20, 22, 28),
                          child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Row(children: [
                                  Expanded(
                                      child: Text('${item['name']}',
                                          style: const TextStyle(
                                              fontSize: 25,
                                              fontWeight: FontWeight.w900))),
                                  IconButton(
                                      onPressed: () =>
                                          Navigator.pop(sheetContext),
                                      icon: const Icon(Icons.close_rounded))
                                ]),
                                Text(
                                    '${item['description'] ?? item['short_description'] ?? ''}',
                                    style: const TextStyle(
                                        color: TablePlayColors.muted,
                                        height: 1.5)),
                                const SizedBox(height: 14),
                                Wrap(spacing: 7, runSpacing: 7, children: [
                                  if (item['preparation_minutes'] != null)
                                    _Info('${item['preparation_minutes']} min',
                                        Icons.schedule_rounded),
                                  if (item['calories'] != null)
                                    _Info('${item['calories']} kcal',
                                        Icons.local_fire_department_outlined),
                                  if (item['spice_level'] != null &&
                                      item['spice_level'] != 'none')
                                    _Info('${item['spice_level']} spice',
                                        Icons.whatshot_rounded),
                                ]),
                                if ('${item['ingredients'] ?? ''}'
                                    .isNotEmpty) ...[
                                  const SizedBox(height: 18),
                                  const Text('Ingredients',
                                      style: TextStyle(
                                          fontWeight: FontWeight.w900)),
                                  const SizedBox(height: 5),
                                  Text('${item['ingredients']}',
                                      style: const TextStyle(
                                          color: TablePlayColors.muted))
                                ],
                                if ((item['allergens'] as List? ?? [])
                                    .isNotEmpty) ...[
                                  const SizedBox(height: 16),
                                  Text(
                                      'Contains: ${(item['allergens'] as List).join(', ')}',
                                      style: const TextStyle(
                                          color: Colors.redAccent,
                                          fontWeight: FontWeight.w700))
                                ],
                                ...options.map((raw) {
                                  final option = Map<String, dynamic>.from(raw);
                                  final choices =
                                      option['choices'] as List? ?? [];
                                  return Padding(
                                      padding: const EdgeInsets.only(top: 18),
                                      child: Column(
                                          crossAxisAlignment:
                                              CrossAxisAlignment.start,
                                          children: [
                                            Text(
                                                '${option['name']}${option['required'] == true ? ' *' : ''}',
                                                style: const TextStyle(
                                                    fontWeight:
                                                        FontWeight.w900)),
                                            const SizedBox(height: 7),
                                            Wrap(
                                                spacing: 7,
                                                children:
                                                    choices.map((rawChoice) {
                                                  final choice =
                                                      Map<String, dynamic>.from(
                                                          rawChoice);
                                                  final name =
                                                      '${choice['name']}';
                                                  return ChoiceChip(
                                                      label: Text(name),
                                                      selected: selected[
                                                              '${option['name']}'] ==
                                                          name,
                                                      onSelected: (_) => setSheet(
                                                          () => selected[
                                                                  '${option['name']}'] =
                                                              name));
                                                }).toList()),
                                          ]));
                                }),
                                const SizedBox(height: 18),
                                TextField(
                                    controller: notes,
                                    maxLines: 2,
                                    decoration: const InputDecoration(
                                        labelText: 'Special request',
                                        prefixIcon:
                                            Icon(Icons.edit_note_rounded))),
                                const SizedBox(height: 16),
                                FilledButton.icon(
                                    onPressed: () {
                                      final optionText = selected.entries
                                          .map((entry) =>
                                              '${entry.key}: ${entry.value}')
                                          .join(', ');
                                      final instruction = [
                                        optionText,
                                        notes.text.trim()
                                      ]
                                          .where((value) => value.isNotEmpty)
                                          .join(' · ');
                                      widget.onAdd(item, instruction);
                                      Navigator.pop(sheetContext);
                                    },
                                    icon: const Icon(
                                        Icons.add_shopping_cart_rounded),
                                    label: Text(
                                        'Add to cart · ₹${item['effective_price'] ?? item['price']}'),
                                    style: FilledButton.styleFrom(
                                        minimumSize: const Size.fromHeight(54),
                                        backgroundColor:
                                            TablePlayColors.accent)),
                              ])),
                    ])));
      }),
    );
    notes.dispose();
  }
}

class _FoodImage extends StatelessWidget {
  const _FoodImage({required this.url, required this.height});
  final String? url;
  final double height;
  @override
  Widget build(BuildContext context) => Semantics(
      image: true,
      label: 'Menu item image',
      child: SizedBox(
          width: double.infinity,
          height: height,
          child: url == null
              ? _fallback()
              : Image.network(url!,
                  fit: BoxFit.cover,
                  errorBuilder: (_, __, ___) => _fallback(),
                  loadingBuilder: (_, child, progress) => progress == null
                      ? child
                      : Container(
                          color: TablePlayColors.canvas,
                          alignment: Alignment.center,
                          child: const CircularProgressIndicator(
                              strokeWidth: 2)))));
  Widget _fallback() => Container(
      color: const Color(0xffffeee5),
      alignment: Alignment.center,
      child: const Icon(Icons.restaurant_rounded,
          size: 42, color: TablePlayColors.accent));
}

class _Badge extends StatelessWidget {
  const _Badge(this.label, this.color);
  final String label;
  final Color color;
  @override
  Widget build(BuildContext context) => Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 5),
      decoration:
          BoxDecoration(color: color, borderRadius: BorderRadius.circular(9)),
      child: Text(label,
          style: const TextStyle(
              color: Colors.white,
              fontSize: 8,
              fontWeight: FontWeight.w900,
              letterSpacing: .6)));
}

class _Info extends StatelessWidget {
  const _Info(this.label, this.icon);
  final String label;
  final IconData icon;
  @override
  Widget build(BuildContext context) => Container(
      padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 6),
      decoration: BoxDecoration(
          color: TablePlayColors.canvas,
          borderRadius: BorderRadius.circular(10)),
      child: Row(mainAxisSize: MainAxisSize.min, children: [
        Icon(icon, size: 15, color: TablePlayColors.accent),
        const SizedBox(width: 5),
        Text(label,
            style: const TextStyle(fontSize: 10, fontWeight: FontWeight.w700))
      ]));
}

class _MiniInfo extends StatelessWidget {
  const _MiniInfo(this.icon, this.label);
  final IconData icon;
  final String label;
  @override
  Widget build(BuildContext context) => Container(
      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 4),
      decoration: BoxDecoration(
          color: TablePlayColors.surfaceMuted,
          borderRadius: BorderRadius.circular(7)),
      child: Row(mainAxisSize: MainAxisSize.min, children: [
        Icon(icon, size: 11, color: TablePlayColors.muted),
        const SizedBox(width: 4),
        Text(label,
            style: const TextStyle(
                color: TablePlayColors.muted,
                fontSize: 8.5,
                fontWeight: FontWeight.w700))
      ]));
}

class _MenuEmpty extends StatelessWidget {
  const _MenuEmpty();
  @override
  Widget build(BuildContext context) => const Center(
          child: Column(mainAxisSize: MainAxisSize.min, children: [
        Icon(Icons.search_off_rounded, size: 52, color: TablePlayColors.muted),
        SizedBox(height: 12),
        Text('No matching dishes',
            style: TextStyle(fontWeight: FontWeight.w900)),
        Text('Try another search or filter.',
            style: TextStyle(color: TablePlayColors.muted))
      ]));
}
