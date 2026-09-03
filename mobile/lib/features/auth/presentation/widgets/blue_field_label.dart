import 'package:flutter/material.dart';

import '../../../../app/theme/blue_theme.dart';

class BlueFieldLabel extends StatelessWidget {
  const BlueFieldLabel(this.text, {super.key});

  final String text;

  @override
  Widget build(BuildContext context) {
    return Text(
      text,
      style: const TextStyle(
        fontFamily: BlueFonts.jakarta,
        fontSize: 13,
        fontWeight: FontWeight.w600,
        letterSpacing: 13 * 0.005,
        color: BlueColors.muted,
      ),
    );
  }
}
