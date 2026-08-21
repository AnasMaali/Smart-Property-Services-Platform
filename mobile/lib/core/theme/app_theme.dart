import 'package:flutter/material.dart';

import '../design/tokens/blue_colors.dart';
import '../design/tokens/blue_radii.dart';
import '../design/tokens/blue_sizes.dart';
import '../design/tokens/blue_typography.dart';

/// Builds BLUE's production Material 3 theme from the locked design
/// tokens in `core/design/tokens`. This is the only place `ThemeData` is
/// assembled - screens and components must consume `Theme.of(context)` or
/// the token classes directly, never construct their own local `ThemeData`
/// or raw colors.
///
/// Only a light theme is implemented for V1 (per product direction: an
/// excellent light theme, architected so a future dark theme is a token
/// addition, not a rewrite - every token file already separates semantic
/// names from raw hex values for exactly this reason).
class AppTheme {
  AppTheme._();

  static ThemeData get light {
    final colorScheme = const ColorScheme.light().copyWith(
      brightness: Brightness.light,
      primary: BlueColors.brandPrimary,
      onPrimary: BlueColors.textInverse,
      primaryContainer: BlueColors.surfaceBrandTint,
      onPrimaryContainer: BlueColors.brandPrimaryStrong,
      secondary: BlueColors.brandAccent,
      onSecondary: BlueColors.textInverse,
      secondaryContainer: BlueColors.brandAccentSoft,
      onSecondaryContainer: BlueColors.brandAccentStrong,
      tertiary: BlueColors.brandSecondary,
      onTertiary: BlueColors.textPrimary,
      error: BlueColors.error,
      onError: BlueColors.textInverse,
      errorContainer: BlueColors.errorSurface,
      onErrorContainer: BlueColors.error,
      surface: BlueColors.surface,
      onSurface: BlueColors.textPrimary,
      surfaceContainerLowest: BlueColors.surface,
      surfaceContainerLow: BlueColors.surfaceSubtle,
      surfaceContainer: BlueColors.surfaceSubtle,
      surfaceContainerHigh: BlueColors.surfaceMuted,
      surfaceContainerHighest: BlueColors.surfaceMuted,
      onSurfaceVariant: BlueColors.textSecondary,
      outline: BlueColors.border,
      outlineVariant: BlueColors.borderSubtle,
      inverseSurface: BlueColors.surfaceDark,
      onInverseSurface: BlueColors.textInverse,
      shadow: BlueColors.textPrimary,
      scrim: BlueColors.textPrimary,
    );

    return ThemeData(
      useMaterial3: true,
      brightness: Brightness.light,
      colorScheme: colorScheme,
      scaffoldBackgroundColor: BlueColors.background,
      canvasColor: BlueColors.background,
      splashFactory: InkSparkle.splashFactory,
      textTheme: BlueTypography.textTheme,
      fontFamily: null,
      appBarTheme: AppBarTheme(
        backgroundColor: BlueColors.background,
        foregroundColor: BlueColors.textPrimary,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        scrolledUnderElevation: 0,
        centerTitle: false,
        titleTextStyle: BlueTypography.sectionTitle,
        toolbarHeight: BlueSizes.appBarHeight,
        iconTheme: const IconThemeData(
          color: BlueColors.textPrimary,
          size: BlueSizes.iconLarge,
        ),
      ),
      scrollbarTheme: const ScrollbarThemeData(),
      dividerTheme: const DividerThemeData(
        color: BlueColors.borderSubtle,
        thickness: 1,
        space: 1,
      ),
      cardTheme: CardThemeData(
        color: BlueColors.surface,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(
          borderRadius: BlueRadii.largeRadius,
          side: const BorderSide(color: BlueColors.borderSubtle),
        ),
      ),
      chipTheme: ChipThemeData(
        backgroundColor: BlueColors.surfaceMuted,
        selectedColor: BlueColors.selectedSurface(),
        disabledColor: BlueColors.surfaceMuted,
        labelStyle: BlueTypography.caption.copyWith(
          color: BlueColors.textPrimary,
        ),
        secondaryLabelStyle: BlueTypography.caption.copyWith(
          color: BlueColors.brandPrimary,
        ),
        side: const BorderSide(color: BlueColors.borderSubtle),
        shape: const StadiumBorder(),
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: BlueColors.primaryAction,
          foregroundColor: BlueColors.textInverse,
          disabledBackgroundColor: BlueColors.disabled,
          disabledForegroundColor: BlueColors.textInverse,
          minimumSize: const Size.fromHeight(BlueSizes.controlHeightLarge),
          textStyle: BlueTypography.button,
          shape: RoundedRectangleBorder(borderRadius: BlueRadii.mediumRadius),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: BlueColors.brandPrimary,
          disabledForegroundColor: BlueColors.disabled,
          side: const BorderSide(color: BlueColors.border),
          minimumSize: const Size.fromHeight(BlueSizes.controlHeightLarge),
          textStyle: BlueTypography.button,
          shape: RoundedRectangleBorder(borderRadius: BlueRadii.mediumRadius),
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(
          foregroundColor: BlueColors.brandPrimary,
          disabledForegroundColor: BlueColors.disabled,
          minimumSize: const Size(0, BlueSizes.controlHeightMedium),
          textStyle: BlueTypography.button,
          shape: RoundedRectangleBorder(borderRadius: BlueRadii.smallRadius),
        ),
      ),
      iconButtonTheme: IconButtonThemeData(
        style: IconButton.styleFrom(
          minimumSize: const Size.square(BlueSizes.minTouchTarget),
          foregroundColor: BlueColors.textPrimary,
        ),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: BlueColors.surface,
        contentPadding: const EdgeInsets.symmetric(
          horizontal: 16,
          vertical: 14,
        ),
        hintStyle: BlueTypography.body.copyWith(color: BlueColors.textTertiary),
        labelStyle: BlueTypography.body.copyWith(
          color: BlueColors.textSecondary,
        ),
        floatingLabelStyle: BlueTypography.caption.copyWith(
          color: BlueColors.brandPrimary,
        ),
        helperStyle: BlueTypography.caption,
        errorStyle: BlueTypography.error,
        errorMaxLines: 2,
        border: OutlineInputBorder(
          borderRadius: BlueRadii.mediumRadius,
          borderSide: const BorderSide(color: BlueColors.border),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BlueRadii.mediumRadius,
          borderSide: const BorderSide(color: BlueColors.border),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BlueRadii.mediumRadius,
          borderSide: const BorderSide(color: BlueColors.focus, width: 2),
        ),
        errorBorder: OutlineInputBorder(
          borderRadius: BlueRadii.mediumRadius,
          borderSide: const BorderSide(color: BlueColors.error),
        ),
        focusedErrorBorder: OutlineInputBorder(
          borderRadius: BlueRadii.mediumRadius,
          borderSide: const BorderSide(color: BlueColors.error, width: 2),
        ),
        disabledBorder: OutlineInputBorder(
          borderRadius: BlueRadii.mediumRadius,
          borderSide: const BorderSide(color: BlueColors.borderSubtle),
        ),
      ),
      bottomNavigationBarTheme: BottomNavigationBarThemeData(
        backgroundColor: BlueColors.surface,
        selectedItemColor: BlueColors.selected,
        unselectedItemColor: BlueColors.textTertiary,
        selectedLabelStyle: BlueTypography.caption,
        unselectedLabelStyle: BlueTypography.caption,
        type: BottomNavigationBarType.fixed,
        elevation: 0,
      ),
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: BlueColors.surface,
        indicatorColor: BlueColors.selectedSurface(),
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        height: BlueSizes.bottomNavHeight,
        labelTextStyle: WidgetStateProperty.resolveWith((states) {
          final selected = states.contains(WidgetState.selected);
          return BlueTypography.caption.copyWith(
            color: selected ? BlueColors.selected : BlueColors.textTertiary,
            fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
          );
        }),
        iconTheme: WidgetStateProperty.resolveWith((states) {
          final selected = states.contains(WidgetState.selected);
          return IconThemeData(
            color: selected ? BlueColors.selected : BlueColors.textTertiary,
            size: BlueSizes.iconLarge,
          );
        }),
      ),
      bottomSheetTheme: const BottomSheetThemeData(
        backgroundColor: BlueColors.surfaceElevated,
        surfaceTintColor: Colors.transparent,
        shape: RoundedRectangleBorder(borderRadius: BlueRadii.sheetTopRadius),
        showDragHandle: true,
        dragHandleColor: BlueColors.border,
      ),
      dialogTheme: DialogThemeData(
        backgroundColor: BlueColors.surfaceElevated,
        surfaceTintColor: Colors.transparent,
        shape: RoundedRectangleBorder(borderRadius: BlueRadii.largeRadius),
        titleTextStyle: BlueTypography.sectionTitle,
        contentTextStyle: BlueTypography.body,
      ),
      snackBarTheme: SnackBarThemeData(
        backgroundColor: BlueColors.textPrimary,
        contentTextStyle: BlueTypography.body.copyWith(
          color: BlueColors.textInverse,
        ),
        actionTextColor: BlueColors.brandAccentBright,
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(borderRadius: BlueRadii.mediumRadius),
      ),
      progressIndicatorTheme: const ProgressIndicatorThemeData(
        color: BlueColors.brandPrimary,
      ),
      switchTheme: SwitchThemeData(
        thumbColor: const WidgetStatePropertyAll(BlueColors.surface),
        trackColor: WidgetStateProperty.resolveWith((states) {
          if (states.contains(WidgetState.disabled)) {
            return BlueColors.surfaceMuted;
          }
          return states.contains(WidgetState.selected)
              ? BlueColors.brandPrimary
              : BlueColors.borderStrong;
        }),
      ),
      checkboxTheme: CheckboxThemeData(
        fillColor: WidgetStateProperty.resolveWith((states) {
          if (states.contains(WidgetState.disabled)) {
            return BlueColors.surfaceMuted;
          }
          return states.contains(WidgetState.selected)
              ? BlueColors.brandPrimary
              : BlueColors.surface;
        }),
        checkColor: const WidgetStatePropertyAll(BlueColors.textInverse),
        side: const BorderSide(color: BlueColors.border, width: 1.5),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(6)),
      ),
      radioTheme: RadioThemeData(
        fillColor: WidgetStateProperty.resolveWith((states) {
          if (states.contains(WidgetState.disabled)) {
            return BlueColors.surfaceMuted;
          }
          return states.contains(WidgetState.selected)
              ? BlueColors.brandPrimary
              : BlueColors.textTertiary;
        }),
      ),
      dividerColor: BlueColors.borderSubtle,
      visualDensity: VisualDensity.standard,
    );
  }
}
