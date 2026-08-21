import 'package:flutter/material.dart';

/// The complete, locked BLUE semantic color system.
///
/// Every raw hex value in the product lives here and nowhere else -
/// screens and components must always consume a semantic token
/// (`BlueColors.textPrimary`, `BlueColors.brandPrimary`, ...), never a
/// literal `Color(0x...)`. This is deliberate: the current BLUE logo mark
/// is not final and may be replaced later, but this palette is locked
/// independently of it - keeping every color reference behind a semantic
/// name means a future rebrand or logo swap never requires touching
/// screen code.
///
/// Source values are the locked BLUE brand palette (indigo primary family,
/// blue support family, gold premium-accent gradient, neutral scale,
/// semantic status colors). Do not add new raw brand hues here without
/// updating the design system documentation - this class is the single
/// source of truth.
abstract final class BlueColors {
  // ---------------------------------------------------------------------
  // Raw palette (private) - only referenced to build the semantic tokens
  // below. Nothing outside this file should reach for these directly.
  // ---------------------------------------------------------------------

  // Primary indigo family.
  static const _deepestIndigo = Color(0xFF17103A);
  static const _brandDeepIndigo = Color(0xFF211451);
  static const _brandIndigo = Color(0xFF2D1B69);
  static const _primaryIndigo = Color(0xFF38247F);
  static const _royalIndigo = Color(0xFF49369A);
  static const _softVioletIndigo = Color(0xFF6556AE);

  // Blue support accent family.
  static const _deepBlue = Color(0xFF244C9A);
  static const _brandBlue = Color(0xFF3264C8);
  static const _brightBlueAccent = Color(0xFF4A7FE0);
  static const _softBlue = Color(0xFFEAF1FF);

  // Original gold/yellow gradient stops (vector-accurate order).
  static const _gold1 = Color(0xFFD69E00);
  static const _gold2 = Color(0xFFF1BA00);
  static const _gold3 = Color(0xFFFFCA00);
  static const _gold4 = Color(0xFFEBB400);

  // Neutrals.
  static const _pureWhite = Color(0xFFFFFFFF);
  static const _appBackground = Color(0xFFF8F9FC);
  static const _secondaryBackground = Color(0xFFF3F5F9);
  static const _subtleSurface = Color(0xFFEEF1F6);
  static const _elevatedSurface = Color(0xFFFFFFFF);
  static const _primaryText = Color(0xFF171821);
  static const _secondaryText = Color(0xFF5F6472);
  static const _tertiaryText = Color(0xFF8C92A0);
  static const _disabledText = Color(0xFFB4B8C2);
  static const _softBorder = Color(0xFFE5E8EF);
  static const _strongBorder = Color(0xFFD4D8E2);
  static const _darkSurface = Color(0xFF17103A);
  static const _darkElevatedSurface = Color(0xFF211451);
  static const _textOnDark = Color(0xFFFFFFFF);
  static const _secondaryTextOnDark = Color(0xFFD8D3E7);

  // Semantic status colors.
  static const _success = Color(0xFF188A57);
  static const _successSurface = Color(0xFFEAF8F1);
  static const _warning = Color(0xFFC88112);
  static const _warningSurface = Color(0xFFFFF5E3);
  static const _error = Color(0xFFC83A4A);
  static const _errorSurface = Color(0xFFFDECEF);
  static const _info = Color(0xFF3264C8);
  static const _infoSurface = Color(0xFFEAF1FF);

  // ---------------------------------------------------------------------
  // BRAND
  // ---------------------------------------------------------------------

  /// Main brand identity color. Primary CTAs, selected nav/controls,
  /// focused elements, brand presence. Use strategically, not everywhere.
  static const brandPrimary = _primaryIndigo;

  /// Deeper indigo for pressed/strong-emphasis brand moments.
  static const brandPrimaryStrong = _brandIndigo;

  /// Deepest indigo - dark hero surfaces, splash, branded dark sections.
  static const brandPrimaryDark = _deepestIndigo;

  /// Lighter indigo tone for soft brand accents on light surfaces
  /// (e.g. icon tint, subtle brand detail) where full-strength
  /// [brandPrimary] would be too heavy.
  static const brandPrimarySoft = _softVioletIndigo;

  /// Supporting blue accent - information states, links, secondary
  /// emphasis. Never competes with indigo as primary hierarchy.
  static const brandAccent = _brandBlue;

  /// Stronger/darker supporting blue for emphasis on blue accents.
  static const brandAccentStrong = _deepBlue;

  /// Soft blue tint surface for supporting-accent backgrounds.
  static const brandAccentSoft = _softBlue;

  /// Bright supporting blue - decorative gradient stops, illustration
  /// accents, focus rings. Not for body text (contrast is borderline).
  static const brandAccentBright = _brightBlueAccent;

  /// Flat (non-gradient) gold for small icon/detail emphasis where a
  /// gradient isn't practical. The darkest gold stop, chosen for the best
  /// contrast of the four. Gold is a premium micro-accent - never body
  /// text, never large fills. See [brandGoldGradient] for the primary
  /// gold treatment.
  static const brandSecondary = _gold1;

  /// The premium gold/yellow gradient, centralized so it is never
  /// reconstructed ad hoc across screens. Simplified 4-stop sequence from
  /// the original vector identity, mostly-horizontal with a subtle angle.
  static const brandGoldGradient = LinearGradient(
    begin: Alignment(-1, -0.15),
    end: Alignment(1, 0.15),
    colors: [_gold1, _gold2, _gold3, _gold4],
    stops: [0.0, 0.36, 0.68, 1.0],
  );

  /// The primary indigo gradient for hero/branded surfaces (e.g. auth
  /// header, splash, hero banners). Use sparingly - most of the app stays
  /// light/neutral.
  static const brandPrimaryGradient = LinearGradient(
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
    colors: [_royalIndigo, _primaryIndigo, _brandDeepIndigo],
    stops: [0.0, 0.55, 1.0],
  );

  // ---------------------------------------------------------------------
  // SURFACES
  // ---------------------------------------------------------------------

  /// The app's base page background.
  static const background = _appBackground;

  /// Default content surface (cards, list rows) on top of [background].
  static const surface = _pureWhite;

  /// Surface for temporarily-elevated content (sheets, dialogs, popovers).
  /// Same color as [surface] by design - depth is communicated with
  /// elevation/shadow and border, not a different fill color.
  static const surfaceElevated = _elevatedSurface;

  /// Grouped-section background, one step down from [surface] - used to
  /// visually separate a group of cards from the page background without
  /// introducing another shadow layer.
  static const surfaceSubtle = _secondaryBackground;

  /// Muted fill for unselected chips, disabled field backgrounds, and
  /// other quiet inline surfaces that need to read as "present but
  /// inactive."
  static const surfaceMuted = _subtleSurface;

  /// Brand-tinted informational surface (banners, info panels, selected
  /// chip backgrounds in the blue family).
  static const surfaceBrandTint = _softBlue;

  /// Dark brand surface, for the rare full-bleed dark/branded section
  /// (splash, auth hero).
  static const surfaceDark = _darkSurface;

  /// Elevated content sitting on top of [surfaceDark] (e.g. a card inside
  /// a dark hero section).
  static const surfaceDarkElevated = _darkElevatedSurface;

  // ---------------------------------------------------------------------
  // TEXT
  // ---------------------------------------------------------------------

  static const textPrimary = _primaryText;
  static const textSecondary = _secondaryText;
  static const textTertiary = _tertiaryText;
  static const textDisabled = _disabledText;

  /// Text on top of [surfaceDark]/[brandPrimaryGradient].
  static const textInverse = _textOnDark;

  /// Secondary-emphasis text on top of [surfaceDark]/[brandPrimaryGradient].
  static const textInverseSecondary = _secondaryTextOnDark;

  // ---------------------------------------------------------------------
  // BORDERS
  // ---------------------------------------------------------------------

  /// Faint dividers/separators.
  static const borderSubtle = _softBorder;

  /// Default, visible border for cards/fields/containers.
  static const border = _strongBorder;

  /// Strongest neutral border tier, for emphasis without using color
  /// (e.g. an unselected-but-focusable control that needs to stand out
  /// more than the default border).
  static const borderStrong = _disabledText;

  // ---------------------------------------------------------------------
  // INTERACTION
  // ---------------------------------------------------------------------

  static const primaryAction = _primaryIndigo;
  static const primaryActionPressed = _brandIndigo;

  /// Secondary button fill (paired with [border] as its outline and
  /// [brandPrimary] as its label color).
  static const secondaryAction = _pureWhite;

  /// Foreground color for a selected control (icon/text/border of a
  /// selected chip, nav destination, segmented control).
  static const selected = _primaryIndigo;

  /// Focus ring / focus outline color - deliberately a distinct hue
  /// (bright blue) from [selected] (indigo) so focus is never confused
  /// with selection.
  static const focus = _brightBlueAccent;

  static const disabled = _disabledText;

  /// Soft indigo tint for a selected control's background fill. Derived
  /// (not a new locked hex) so it always stays in lockstep with
  /// [brandPrimary].
  static Color selectedSurface({double opacity = 0.08}) =>
      brandPrimary.withValues(alpha: opacity);

  /// Neutral pressed-state overlay for non-branded controls (list rows,
  /// icon buttons).
  static Color pressedOverlay({double opacity = 0.06}) =>
      Colors.black.withValues(alpha: opacity);

  // ---------------------------------------------------------------------
  // SEMANTIC
  // ---------------------------------------------------------------------

  static const success = _success;
  static const successSurface = _successSurface;
  static const warning = _warning;
  static const warningSurface = _warningSurface;
  static const error = _error;
  static const errorSurface = _errorSurface;
  static const info = _info;
  static const infoSurface = _infoSurface;
}
