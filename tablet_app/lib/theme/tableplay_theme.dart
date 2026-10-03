import 'package:flutter/material.dart';

abstract final class TablePlayColors {
  static const deep = Color(0xff123c35);
  static const deeper = Color(0xff092922);
  static const accent = Color(0xfff06435);
  static const accentSoft = Color(0xffffeee7);
  static const gold = Color(0xfff4bd61);
  static const canvas = Color(0xfff4f7f5);
  static const surfaceMuted = Color(0xffedf3f0);
  static const text = Color(0xff18231f);
  static const muted = Color(0xff64736c);
  static const border = Color(0xffdce6e1);
  static const success = Color(0xff17855d);
  static const warning = Color(0xffb86b12);
  static const danger = Color(0xffc34836);
  static const info = Color(0xff326b9a);
}

ThemeData tablePlayTheme() {
  final scheme = ColorScheme.fromSeed(
    seedColor: TablePlayColors.deep,
    primary: TablePlayColors.deep,
    secondary: TablePlayColors.accent,
    surface: Colors.white,
    brightness: Brightness.light,
  );
  final base = ThemeData(colorScheme: scheme, useMaterial3: true);
  final textTheme = base.textTheme.apply(
      bodyColor: TablePlayColors.text, displayColor: TablePlayColors.text);
  return base.copyWith(
    scaffoldBackgroundColor: TablePlayColors.canvas,
    textTheme: textTheme.copyWith(
      headlineLarge: textTheme.headlineLarge
          ?.copyWith(fontWeight: FontWeight.w900, letterSpacing: -1.1),
      headlineMedium: textTheme.headlineMedium
          ?.copyWith(fontWeight: FontWeight.w900, letterSpacing: -.8),
      headlineSmall: textTheme.headlineSmall
          ?.copyWith(fontWeight: FontWeight.w900, letterSpacing: -.45),
      titleLarge: textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w900),
      titleMedium: textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w800),
      labelLarge: textTheme.labelLarge?.copyWith(fontWeight: FontWeight.w800),
    ),
    appBarTheme: const AppBarTheme(
        elevation: 0,
        scrolledUnderElevation: 0,
        toolbarHeight: 68,
        centerTitle: false,
        backgroundColor: TablePlayColors.deep,
        foregroundColor: Colors.white,
        surfaceTintColor: Colors.transparent,
        titleTextStyle: TextStyle(
            color: Colors.white,
            fontSize: 19,
            fontWeight: FontWeight.w800,
            letterSpacing: -0.3)),
    cardTheme: CardThemeData(
      elevation: 0,
      margin: EdgeInsets.zero,
      color: Colors.white,
      surfaceTintColor: Colors.transparent,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(20),
        side: const BorderSide(color: TablePlayColors.border),
      ),
    ),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: Colors.white,
      contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 15),
      border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: const BorderSide(color: TablePlayColors.border)),
      enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: const BorderSide(color: TablePlayColors.border)),
      focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide:
              const BorderSide(color: TablePlayColors.accent, width: 1.5)),
    ),
    navigationBarTheme: NavigationBarThemeData(
      height: 72,
      backgroundColor: Colors.white,
      indicatorColor: TablePlayColors.accent.withValues(alpha: .14),
      labelTextStyle: WidgetStateProperty.resolveWith((states) => TextStyle(
          fontSize: 12,
          fontWeight: states.contains(WidgetState.selected)
              ? FontWeight.w800
              : FontWeight.w600,
          color: states.contains(WidgetState.selected)
              ? TablePlayColors.deep
              : TablePlayColors.muted)),
    ),
    filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
            backgroundColor: TablePlayColors.deep,
            foregroundColor: Colors.white,
            minimumSize: const Size(0, 48),
            padding: const EdgeInsets.symmetric(horizontal: 19, vertical: 13),
            shape:
                RoundedRectangleBorder(borderRadius: BorderRadius.circular(13)),
            textStyle: const TextStyle(fontWeight: FontWeight.w800))),
    outlinedButtonTheme: OutlinedButtonThemeData(
      style: OutlinedButton.styleFrom(
        minimumSize: const Size(0, 46),
        padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 13),
        side: const BorderSide(color: TablePlayColors.border),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(13)),
        textStyle: const TextStyle(fontWeight: FontWeight.w800),
      ),
    ),
    textButtonTheme: TextButtonThemeData(
      style: TextButton.styleFrom(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(11)),
        textStyle: const TextStyle(fontWeight: FontWeight.w800),
      ),
    ),
    iconButtonTheme: IconButtonThemeData(
      style: IconButton.styleFrom(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      ),
    ),
    chipTheme: base.chipTheme.copyWith(
      backgroundColor: Colors.white,
      selectedColor: TablePlayColors.accentSoft,
      side: const BorderSide(color: TablePlayColors.border),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(11)),
      labelStyle: const TextStyle(
        color: TablePlayColors.text,
        fontWeight: FontWeight.w800,
        fontSize: 11,
      ),
      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 4),
    ),
    snackBarTheme: SnackBarThemeData(
        backgroundColor: TablePlayColors.deeper,
        contentTextStyle: const TextStyle(color: Colors.white),
        actionTextColor: TablePlayColors.gold,
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(13))),
    bottomSheetTheme: const BottomSheetThemeData(
      backgroundColor: Colors.white,
      surfaceTintColor: Colors.transparent,
      showDragHandle: true,
    ),
    dialogTheme: DialogThemeData(
      backgroundColor: Colors.white,
      surfaceTintColor: Colors.transparent,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(22)),
    ),
    dividerColor: TablePlayColors.border,
  );
}
