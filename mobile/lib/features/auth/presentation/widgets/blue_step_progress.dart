import 'package:flutter/material.dart';

import '../../../../app/theme/blue_theme.dart';

class BlueStepProgress extends StatelessWidget {
  const BlueStepProgress({
    super.key,
    required this.step,
    required this.label,
    required this.title,
    this.total = 2,
  });

  final int step;
  final int total;
  final String label;
  final String title;

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Row(
          crossAxisAlignment: CrossAxisAlignment.baseline,
          textBaseline: TextBaseline.alphabetic,
          children: [
            Text(
              label,
              style: const TextStyle(
                fontFamily: BlueFonts.jakarta,
                fontSize: 12.5,
                fontWeight: FontWeight.w700,
                letterSpacing: 12.5 * 0.015,
                color: BlueColors.ink,
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Text(
                title,
                textAlign: TextAlign.end,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 12.5,
                  fontWeight: FontWeight.w500,
                  color: BlueColors.muted,
                ),
              ),
            ),
          ],
        ),
        const SizedBox(height: 9),
        Row(
          children: [
            for (var i = 1; i <= total; i++) ...[
              if (i > 1) const SizedBox(width: BlueDimens.progressGap),
              Expanded(
                child: AnimatedContainer(
                  duration: const Duration(milliseconds: 280),
                  curve: const Cubic(0.22, 0.61, 0.36, 1),
                  height: BlueDimens.progressHeight,
                  decoration: BoxDecoration(
                    color: i <= step ? BlueColors.ink : BlueColors.border,
                    borderRadius: BorderRadius.circular(2),
                  ),
                ),
              ),
            ],
          ],
        ),
      ],
    );
  }
}
