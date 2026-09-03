import 'package:flutter/material.dart';

import '../../../app/theme/blue_theme.dart';
import '../../auth/presentation/widgets/blue_motion.dart';
import '../data/rating_models.dart';
import 'widgets/rating_widgets.dart';

class AlreadyRatedPage extends StatelessWidget {
  const AlreadyRatedPage({super.key, required this.view});

  final AlreadyRatedView view;

  @override
  Widget build(BuildContext context) {
    final gutter = ratingGutter(context);
    return Scaffold(
      backgroundColor: BlueColors.canvas,
      body: SafeArea(
        bottom: false,
        child: BlueEnter(
          duration: BlueMotion.rise,
          offset: const Offset(0, 0.018),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Padding(
                padding: EdgeInsets.fromLTRB(gutter, 2, gutter, 0),
                child: Align(
                  alignment: Alignment.centerLeft,
                  child: RatingBackButton(
                    onPressed: () => Navigator.of(context).maybePop(),
                  ),
                ),
              ),
              Expanded(
                child: ListView(
                  physics: const BouncingScrollPhysics(
                    parent: AlwaysScrollableScrollPhysics(),
                  ),
                  padding: const EdgeInsets.fromLTRB(0, 6, 0, 34),
                  children: [
                    Padding(
                      padding: EdgeInsets.symmetric(horizontal: gutter),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          RatingStatusBadge(label: view.statusLabel),
                          const SizedBox(height: 10),
                          RatingTitle(view.title),
                          RatingMeta(
                            reference: view.reference,
                            rest: view.metaRest,
                          ),
                          const SizedBox(height: 26),
                          const RatingEyebrow('Your rating'),
                          const SizedBox(height: 11),
                        ],
                      ),
                    ),
                    RatingSubmittedBlock(view: view),
                    Padding(
                      padding: EdgeInsets.fromLTRB(gutter, 16, gutter, 0),
                      child: const RatingHelper(
                        "Ratings can't be changed once submitted. If something is wrong, open a support request.",
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
