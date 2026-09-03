import 'package:flutter/material.dart';

import '../../../../app/theme/blue_theme.dart';

class BlueErrorHint extends StatelessWidget {
  const BlueErrorHint({super.key, required this.message});

  final String message;

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Container(
          width: 15,
          height: 15,
          margin: const EdgeInsets.only(top: 1),
          alignment: Alignment.center,
          decoration: const BoxDecoration(
            color: BlueColors.error,
            shape: BoxShape.circle,
          ),
          child: const Text(
            '!',
            style: TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 10.5,
              fontWeight: FontWeight.w800,
              height: 1,
              color: BlueColors.white,
            ),
          ),
        ),
        const SizedBox(width: 7),
        Expanded(
          child: Text(
            message,
            style: const TextStyle(
              fontFamily: BlueFonts.jakarta,
              fontSize: 12.5,
              height: 1.4,
              fontWeight: FontWeight.w500,
              color: BlueColors.error,
            ),
          ),
        ),
      ],
    );
  }
}
