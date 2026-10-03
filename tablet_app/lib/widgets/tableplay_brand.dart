import 'package:flutter/material.dart';

import '../theme/tableplay_theme.dart';

class TablePlayMark extends StatelessWidget {
  const TablePlayMark({super.key, this.size = 48});

  final double size;

  @override
  Widget build(BuildContext context) => Container(
        width: size,
        height: size,
        decoration: BoxDecoration(
          gradient: const LinearGradient(
            colors: [TablePlayColors.accent, Color(0xffff8a54)],
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
          ),
          borderRadius: BorderRadius.circular(size * .28),
          boxShadow: [
            BoxShadow(
              color: TablePlayColors.deeper.withValues(alpha: .22),
              blurRadius: size * .28,
              offset: Offset(0, size * .1),
            ),
          ],
        ),
        alignment: Alignment.center,
        child: Container(
          width: size * .62,
          height: size * .43,
          decoration: BoxDecoration(
            color: const Color(0xfffffaf1),
            borderRadius: BorderRadius.circular(size * .11),
          ),
          alignment: Alignment.center,
          child: Icon(
            Icons.sports_esports_rounded,
            size: size * .34,
            color: TablePlayColors.deep,
          ),
        ),
      );
}

class TablePlayBrand extends StatelessWidget {
  const TablePlayBrand({
    super.key,
    this.light = false,
    this.compact = false,
    this.logoUrl,
    this.title = 'TablePlay',
    this.tagline = 'DINE · PLAY · DELIGHT',
  });

  final bool light;
  final bool compact;
  final String? logoUrl;
  final String title;
  final String tagline;

  @override
  Widget build(BuildContext context) => Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (logoUrl == null || logoUrl!.isEmpty)
            TablePlayMark(size: compact ? 36 : 46)
          else
            ClipRRect(
              borderRadius: BorderRadius.circular(compact ? 10 : 13),
              child: Image.network(
                logoUrl!,
                width: compact ? 36 : 46,
                height: compact ? 36 : 46,
                fit: BoxFit.cover,
                errorBuilder: (_, __, ___) =>
                    TablePlayMark(size: compact ? 36 : 46),
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
                    fontSize: 22,
                    fontWeight: FontWeight.w900,
                    letterSpacing: -.7,
                    height: 1,
                    color: light ? Colors.white : TablePlayColors.deep,
                  ),
                ),
                if (!compact) ...[
                  const SizedBox(height: 5),
                  Text(
                    tagline.toUpperCase(),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                      color: light ? Colors.white60 : TablePlayColors.muted,
                      fontSize: 9,
                      fontWeight: FontWeight.w800,
                      letterSpacing: 1.1,
                    ),
                  ),
                ],
              ],
            ),
          ),
        ],
      );
}
