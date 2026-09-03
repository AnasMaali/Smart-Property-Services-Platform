import 'package:flutter/material.dart';

import '../../../../app/theme/blue_theme.dart';
import '../../../auth/presentation/widgets/blue_motion.dart';
import '../../../home/presentation/widgets/home_icons.dart';

class BlueEmptyTab extends StatelessWidget {
  const BlueEmptyTab({
    super.key,
    required this.glyph,
    required this.title,
    required this.message,
  });

  final BlueGlyph glyph;
  final String title;
  final String message;

  @override
  Widget build(BuildContext context) {
    return ColoredBox(
      color: BlueColors.canvas,
      child: SafeArea(
        bottom: false,
        child: BlueEnter(
          offset: const Offset(0, 0.03),
          child: Padding(
            padding: const EdgeInsets.fromLTRB(32, 0, 32, 40),
            child: Column(
              children: [
                const Spacer(),
                Container(
                  width: 64,
                  height: 64,
                  decoration: BoxDecoration(
                    color: BlueColors.white,
                    borderRadius: BorderRadius.circular(20),
                    border: Border.all(color: BlueColors.border),
                  ),
                  alignment: Alignment.center,
                  child: BlueGlyphIcon(
                    glyph,
                    size: 26,
                    color: BlueColors.ink,
                    strokeWidth: 1.8,
                  ),
                ),
                const SizedBox(height: 18),
                Text(
                  title,
                  style: const TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 22,
                    height: 1.2,
                    fontWeight: FontWeight.w700,
                    letterSpacing: 22 * -0.02,
                    color: BlueColors.ink,
                  ),
                ),
                const SizedBox(height: 8),
                Text(
                  message,
                  textAlign: TextAlign.center,
                  style: const TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 14.5,
                    height: 1.5,
                    fontWeight: FontWeight.w400,
                    color: BlueColors.muted,
                  ),
                ),
                const Spacer(flex: 2),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
