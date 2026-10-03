import 'package:flutter/material.dart';

import '../theme/tableplay_theme.dart';

class TablePlayBrand extends StatelessWidget {
  const TablePlayBrand({
    super.key,
    this.light = false,
    this.logoUrl,
    this.title = 'TablePlay',
    this.subtitle = 'STAFF OPERATIONS',
  });
  final bool light;
  final String? logoUrl;
  final String title;
  final String subtitle;
  @override
  Widget build(BuildContext context) => Row(
    mainAxisSize: MainAxisSize.min,
    children: [
      Container(
        width: 44,
        height: 44,
        decoration: BoxDecoration(
          gradient: const LinearGradient(
            colors: [TablePlayColors.accent, Color(0xffff8a54)],
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
          ),
          borderRadius: BorderRadius.circular(14),
          boxShadow: [
            BoxShadow(
              color: TablePlayColors.deeper.withValues(alpha: .2),
              blurRadius: 14,
              offset: const Offset(0, 6),
            ),
          ],
        ),
        clipBehavior: Clip.antiAlias,
        child: logoUrl == null || logoUrl!.isEmpty
            ? const Icon(Icons.restaurant_rounded, color: Colors.white)
            : Image.network(
                logoUrl!,
                fit: BoxFit.cover,
                errorBuilder: (_, __, ___) =>
                    const Icon(Icons.restaurant_rounded, color: Colors.white),
              ),
      ),
      const SizedBox(width: 11),
      Flexible(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              title,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                fontSize: 20,
                height: 1,
                letterSpacing: -.55,
                fontWeight: FontWeight.w900,
                color: light ? Colors.white : TablePlayColors.deep,
              ),
            ),
            const SizedBox(height: 5),
            Text(
              subtitle,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                fontSize: 8,
                letterSpacing: 1.25,
                fontWeight: FontWeight.w900,
                color: light ? Colors.white60 : TablePlayColors.muted,
              ),
            ),
          ],
        ),
      ),
    ],
  );
}

class StatTile extends StatelessWidget {
  const StatTile({
    super.key,
    required this.label,
    required this.value,
    required this.icon,
    this.color,
    this.detail,
  });
  final String label, value;
  final IconData icon;
  final Color? color;
  final String? detail;
  @override
  Widget build(BuildContext context) => Card(
    child: Padding(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 38,
                height: 38,
                decoration: BoxDecoration(
                  color: (color ?? TablePlayColors.accent).withValues(
                    alpha: .11,
                  ),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Icon(
                  icon,
                  size: 20,
                  color: color ?? TablePlayColors.accent,
                ),
              ),
              const Spacer(),
              if (detail != null)
                Flexible(
                  child: Text(
                    detail!,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    textAlign: TextAlign.end,
                    style: const TextStyle(
                      fontSize: 9,
                      fontWeight: FontWeight.w800,
                      color: TablePlayColors.muted,
                    ),
                  ),
                ),
            ],
          ),
          const SizedBox(height: 18),
          Text(
            value,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(
              fontSize: 26,
              height: 1,
              letterSpacing: -.7,
              fontWeight: FontWeight.w900,
            ),
          ),
          const SizedBox(height: 5),
          Text(
            label,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(
              fontSize: 10.5,
              fontWeight: FontWeight.w700,
              color: TablePlayColors.muted,
            ),
          ),
        ],
      ),
    ),
  );
}

class SectionTitle extends StatelessWidget {
  const SectionTitle(this.title, {super.key, this.subtitle, this.trailing});
  final String title;
  final String? subtitle;
  final Widget? trailing;
  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.fromLTRB(2, 22, 2, 11),
    child: Row(
      children: [
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                title,
                style: const TextStyle(
                  fontSize: 19,
                  letterSpacing: -.35,
                  fontWeight: FontWeight.w900,
                ),
              ),
              if (subtitle != null)
                Padding(
                  padding: const EdgeInsets.only(top: 3),
                  child: Text(
                    subtitle!,
                    style: const TextStyle(
                      fontSize: 11,
                      height: 1.35,
                      color: TablePlayColors.muted,
                    ),
                  ),
                ),
            ],
          ),
        ),
        if (trailing != null) trailing!,
      ],
    ),
  );
}

class StatusPill extends StatelessWidget {
  const StatusPill(this.value, {super.key});
  final String value;
  @override
  Widget build(BuildContext context) {
    final normalized = value.toLowerCase();
    final color =
        [
          'ready',
          'paid',
          'completed',
          'available',
          'healthy',
          'active',
        ].contains(normalized)
        ? TablePlayColors.success
        : ['pending', 'open', 'acknowledged', 'confirmed'].contains(normalized)
        ? const Color(0xffb86b12)
        : ['rejected', 'cancelled', 'failed', 'disabled'].contains(normalized)
        ? TablePlayColors.danger
        : TablePlayColors.deep;
    final icon = switch (normalized) {
      'ready' ||
      'paid' ||
      'completed' ||
      'healthy' ||
      'active' => Icons.check_circle_rounded,
      'pending' ||
      'open' ||
      'acknowledged' ||
      'confirmed' => Icons.schedule_rounded,
      'rejected' ||
      'cancelled' ||
      'failed' ||
      'disabled' => Icons.error_rounded,
      'preparing' => Icons.soup_kitchen_rounded,
      'served' => Icons.room_service_rounded,
      _ => Icons.circle,
    };
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 6),
      decoration: BoxDecoration(
        color: color.withValues(alpha: .1),
        borderRadius: BorderRadius.circular(99),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 11, color: color),
          const SizedBox(width: 5),
          Text(
            value.replaceAll('_', ' ').toUpperCase(),
            style: TextStyle(
              fontSize: 8,
              letterSpacing: .55,
              fontWeight: FontWeight.w900,
              color: color,
            ),
          ),
        ],
      ),
    );
  }
}

class OperationalContextBar extends StatelessWidget {
  const OperationalContextBar({
    super.key,
    required this.role,
    required this.serverHost,
    required this.planName,
    required this.online,
    required this.refreshing,
    required this.onRefresh,
    this.lastUpdatedAt,
  });

  final String role;
  final String serverHost;
  final String planName;
  final bool online;
  final bool refreshing;
  final VoidCallback onRefresh;
  final DateTime? lastUpdatedAt;

  @override
  Widget build(BuildContext context) {
    final updated = lastUpdatedAt == null
        ? 'Waiting for first sync'
        : 'Updated ${_clock(lastUpdatedAt!)}';
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 11),
      decoration: const BoxDecoration(
        color: Colors.white,
        border: Border(bottom: BorderSide(color: TablePlayColors.border)),
      ),
      child: Row(
        children: [
          Expanded(
            child: SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: Row(
                children: [
                  _ContextChip(
                    icon: online ? Icons.lan_rounded : Icons.cloud_off_rounded,
                    label: online ? 'Server online' : 'Connection issue',
                    color: online
                        ? TablePlayColors.success
                        : TablePlayColors.danger,
                  ),
                  const SizedBox(width: 8),
                  _ContextChip(
                    icon: Icons.badge_outlined,
                    label: '${_title(role)} workspace',
                  ),
                  const SizedBox(width: 8),
                  _ContextChip(
                    icon: Icons.workspace_premium_outlined,
                    label: planName,
                    color: TablePlayColors.warning,
                  ),
                  const SizedBox(width: 8),
                  _ContextChip(icon: Icons.dns_outlined, label: serverHost),
                ],
              ),
            ),
          ),
          const SizedBox(width: 12),
          Text(
            updated,
            style: const TextStyle(
              color: TablePlayColors.muted,
              fontSize: 10,
              fontWeight: FontWeight.w700,
            ),
          ),
          const SizedBox(width: 6),
          IconButton(
            onPressed: refreshing ? null : onRefresh,
            tooltip: 'Refresh live data',
            icon: refreshing
                ? const SizedBox.square(
                    dimension: 17,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const Icon(Icons.refresh_rounded, size: 20),
          ),
        ],
      ),
    );
  }

  static String _clock(DateTime value) {
    final local = value.toLocal();
    final hour = local.hour == 0
        ? 12
        : local.hour > 12
        ? local.hour - 12
        : local.hour;
    final minute = local.minute.toString().padLeft(2, '0');
    return '$hour:$minute ${local.hour >= 12 ? 'PM' : 'AM'}';
  }

  static String _title(String value) => value.isEmpty
      ? value
      : '${value.substring(0, 1).toUpperCase()}${value.substring(1)}';
}

class _ContextChip extends StatelessWidget {
  const _ContextChip({required this.icon, required this.label, this.color});

  final IconData icon;
  final String label;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final resolved = color ?? TablePlayColors.deep;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 7),
      decoration: BoxDecoration(
        color: resolved.withValues(alpha: .07),
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: resolved.withValues(alpha: .14)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 14, color: resolved),
          const SizedBox(width: 6),
          Text(
            label,
            style: TextStyle(
              color: resolved,
              fontSize: 9.5,
              fontWeight: FontWeight.w800,
            ),
          ),
        ],
      ),
    );
  }
}

String tableCode(Map item) =>
    item['table_session']?['dining_table']?['table_code']?.toString() ??
    item['dining_table']?['table_code']?.toString() ??
    'Table';
