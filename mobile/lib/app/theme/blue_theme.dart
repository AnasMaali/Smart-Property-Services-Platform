import 'package:flutter/material.dart';

import '../../features/auth/presentation/widgets/blue_motion.dart';

/// Tokens taken 1:1 from BLUE Auth.html (PhoneLogin / OtpVerify).
abstract final class BlueColors {
  static const Color ink = Color(0xFF140050);
  static const Color canvas = Color(0xFFF8F8FB);
  static const Color white = Color(0xFFFFFFFF);
  static const Color muted = Color(0xFF565D82);
  static const Color placeholder = Color(0xFF6B7396);
  static const Color border = Color(0xFFE3E5EF);
  static const Color chevron = Color(0xFF7C84A8);
  static const Color press = Color(0xFFF1F1F7);
  static const Color error = Color(0xFF963025);
  static const Color verified = Color(0xFF1E7A5A);
  static const Color glowInk = Color(0x12140050);
  static const Color glowError = Color(0x12963025);
  static const Color buttonShadow = Color(0xA6140050);
  static const Color uaeRed = Color(0xFFCE1126);
  static const Color uaeGreen = Color(0xFF009739);
  static const Color uaeBlack = Color(0xFF111111);
  static const Color areaLocked = Color(0xFFF4F4F8);
  static const Color sheetScrim = Color(0x570C042C);
  static const Color dash = Color(0xFFC9CCDC);
  static const Color badgeBorder = Color(0xFFDFE1EC);
  static const Color selectPress = Color(0xFFFCFCFE);
  static const Color rowPress = Color(0xFFF5F5F9);
  static const Color sheetHairline = Color(0xFFF0F1F6);
  static const Color tileFill = Color(0xFFEDEDF3);
  static const Color skeleton = Color(0xFFE9EAF1);
  static const Color navLine = Color(0xFFE9EAF1);
  static const Color navBar = Color(0xF5FFFFFF);
  static const Color alert = Color(0xFFE6AD1C);
  static const Color gold = Color(0xFFF1BA00);
  static const Color goldDeep = Color(0xFFD69E00);
  static const Color glyph = Color(0xFFB7B6CE);
  static const Color chipFill = Color(0xFFEEEFF5);
  static const Color unavailableFill = Color(0xFFF8E4D2);
  static const Color unavailableText = Color(0xFFA35A28);
  static const Color body = Color(0xFF3E4675);
  static const Color chipInk = Color(0xFF3E4675);
  static const Color chipSurface = Color(0xFFF2F3F9);
  static const Color mediaFill = Color(0xFFEDEFF5);
  static const Color mediaLine = Color(0xFFE7E9F1);
  static const Color photoMute = Color(0xFF9AA2BE);
  static const Color ctaDisabled = Color(0xFFE8E9F0);
  static const Color ctaDisabledText = Color(0xFF8A90AC);
  static const Color unavailableInk = Color(0xFF7A4A20);
  static const Color unavailableSurface = Color(0xFFFBF3E9);
  static const Color unavailableLine = Color(0xFFEFE0CD);
  static const Color fieldError = Color(0xFFD9A49C);
  static const Color choiceError = Color(0xFFDCA9A2);
  static const Color checkLine = Color(0xFFD5D8E6);
  static const Color goldMid = Color(0xFFFFCA00);
  static const Color goldWarm = Color(0xFFEBB400);
  static const Color thumbCool = Color(0xFFDCE3F1);
  static const Color thumbMint = Color(0xFFD6E6E2);
  static const Color thumbLilac = Color(0xFFDEDCEF);
  static const Color ghostLine = Color(0xFFD8DBE8);
  static const Color ghostPress = Color(0xFFF6F6FB);
  static const Color barFill = Color(0xF7FFFFFF);
  static const Color ctaBusy = Color(0xFF3A2A78);
  static const Color dateFull = Color(0xFF9AA0B8);
  static const Color dateFullLine = Color(0xFFEAEBF2);
  static const Color skeletonAlt = Color(0xFFEDEEF4);
  static const Color laterPress = Color(0xFFF4F4F9);
  static const Color selectedMute = Color(0xB8FFFFFF);
  static const Color paymentSheet = Color(0xFFEEEFF4);
  static const Color paymentDash = Color(0xFFC7CBDA);
  static const Color methodScrim = Color(0x47140050);
  static const Color providerScrim = Color(0x57140050);
  static const Color rowChevron = Color(0xFFB0B6CC);
  static const Color locationDot = Color(0xFFC4C9DA);
  static const Color plusStroke = Color(0xFF5F678B);
  static const Color toastLift = Color(0xBF140050);
  static const Color reminderFill = Color(0xFFF6E6D4);
  static const Color reminderInk = Color(0xFF8A5A2B);
  static const Color serviceTagFill = Color(0xFFE4E1F4);
  static const Color statusTagFill = Color(0xFFE8E6F6);
  static const Color plusFill = Color(0xFFFFE082);
  static const Color plusLine = Color(0xFFE0A106);
  static const Color heroWash = Color(0xFFE6E8F0);
  static const Color houseNight = Color(0xFF2A2158);
  static const Color windowGlow = Color(0xFFFFE08A);
  static const Color bookingBanner = Color(0xFF1B1464);
  static const Color bookingBannerEnd = Color(0xFF3A2A78);
  static const Color cardShadow = Color(0x140C042C);
}

abstract final class BlueFonts {
  static const String jakarta = 'Plus Jakarta Sans';
  static const String poppins = 'Poppins';
  static const String mono = 'IBM Plex Mono';
}

abstract final class BlueDimens {
  static const double screenGutter = 24;
  static const double contentTop = 34;
  static const double contentBottom = 34;
  static const double fieldHeight = 58;
  static const double fieldRadius = 20;
  static const double otpBoxHeight = 60;
  static const double otpBoxRadius = 16;
  static const double penguinHeight = 74;
  static const double penguinWidth = 43.8;
  static const double wordmarkSize = 30;
  static const double wordmarkGap = 10;
  static const double compactPenguinHeight = 52;
  static const double compactPenguinWidth = 31;
  static const double compactWordmarkSize = 21;
  static const double compactWordmarkGap = 7;
  static const double progressGap = 6;
  static const double progressHeight = 3;
  static const double homeGutter = 22;
  static const double tilePhoto = 104;
  static const double tileRadius = 18;
  static const double serviceThumbWidth = 100;
  static const double serviceThumbHeight = 76;
  static const double serviceThumbRadius = 14;
  static const double serviceRowHeight = 102;
  static const double categoryChipHeight = 40;
  static const double searchFieldHeight = 48;
  static const double cartThumbWidth = 72;
  static const double cartThumbHeight = 56;
  static const double cartThumbRadius = 12;
  static const double cartStepperWidth = 126;
  static const double cartStepperHeight = 44;
  static const double cartBarHeight = 72;
  static const double cartCtaHeight = 50;
  static const double checkoutGutter = 22;
  static const double checkoutTitle = 26;
  static const double checkoutIcon = 17;
  static const double checkoutCtaHeight = 52;
  static const double checkoutGoldWidth = 15;
  static const double checkoutGoldHeight = 2;
  static const double checkoutHoldDot = 7;
  static const double appointmentDateWidth = 60;
  static const double appointmentDateHeight = 76;
  static const double appointmentSlotHeight = 56;
  static const double paymentAmount = 34;
  static const double paymentMark = 62;
  static const double paymentCta = 52;
  static const double paymentMethodRow = 64;
  static const double propertyIcon = 38;
  static const double propertyRow = 96;
}

ThemeData buildBlueTheme() {
  final base = ThemeData(
    useMaterial3: true,
    brightness: Brightness.light,
    fontFamily: BlueFonts.jakarta,
    scaffoldBackgroundColor: BlueColors.canvas,
    colorScheme: const ColorScheme.light(
      primary: BlueColors.ink,
      onPrimary: BlueColors.white,
      surface: BlueColors.canvas,
      onSurface: BlueColors.ink,
      error: BlueColors.error,
    ),
  );

  return base.copyWith(
    textTheme: base.textTheme.apply(
      fontFamily: BlueFonts.jakarta,
      bodyColor: BlueColors.ink,
      displayColor: BlueColors.ink,
    ),
    splashFactory: NoSplash.splashFactory,
    highlightColor: Colors.transparent,
    splashColor: Colors.transparent,
    pageTransitionsTheme: const PageTransitionsTheme(
      builders: {
        TargetPlatform.android: BluePageTransitionsBuilder(),
        TargetPlatform.iOS: BluePageTransitionsBuilder(),
        TargetPlatform.windows: BluePageTransitionsBuilder(),
        TargetPlatform.macOS: BluePageTransitionsBuilder(),
        TargetPlatform.linux: BluePageTransitionsBuilder(),
      },
    ),
  );
}
