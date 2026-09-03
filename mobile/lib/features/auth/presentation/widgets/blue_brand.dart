import 'package:flutter/material.dart';

import '../../../../app/theme/blue_theme.dart';

class BlueBrand extends StatelessWidget {
  const BlueBrand({super.key, this.compact = false});

  final bool compact;

  @override
  Widget build(BuildContext context) {
    final width = compact
        ? BlueDimens.compactPenguinWidth
        : BlueDimens.penguinWidth;
    final height = compact
        ? BlueDimens.compactPenguinHeight
        : BlueDimens.penguinHeight;
    final word = compact
        ? BlueDimens.compactWordmarkSize
        : BlueDimens.wordmarkSize;
    final gap = compact
        ? BlueDimens.compactWordmarkGap
        : BlueDimens.wordmarkGap;
    return Column(
      children: [
        Transform.translate(
          offset: Offset(compact ? 1 : 2, 0),
          child: Image.asset(
            'assets/brand/penguin.png',
            width: width,
            height: height,
            fit: BoxFit.contain,
            filterQuality: FilterQuality.high,
          ),
        ),
        SizedBox(height: gap),
        Text(
          'blue',
          style: TextStyle(
            fontFamily: BlueFonts.poppins,
            fontWeight: FontWeight.w800,
            fontSize: word,
            height: 1,
            letterSpacing: word * -0.04,
            color: BlueColors.ink,
          ),
        ),
      ],
    );
  }
}
