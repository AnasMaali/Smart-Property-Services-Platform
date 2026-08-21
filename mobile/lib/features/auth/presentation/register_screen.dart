import 'package:flutter/material.dart';
import 'package:flutter/widget_previews.dart';
import 'package:go_router/go_router.dart';

import '../../../core/design/components/blue_buttons.dart';
import '../../../core/design/components/blue_fields.dart';
import '../../../core/design/components/blue_pickers.dart';
import '../../../core/design/components/blue_selection.dart';
import '../../../core/design/tokens/blue_colors.dart';
import '../../../core/design/tokens/blue_radii.dart';
import '../../../core/design/tokens/blue_spacing.dart';
import '../../../core/design/tokens/blue_typography.dart';
import '../../profile/data/reference_data.dart';
import '../../profile/data/reference_data_fixtures.dart';

/// `POST /v1/auth/register` (blueprint §2.1) needs eight fields across
/// three genuinely different topics (identity, credentials, home
/// context) - a guided 3-step flow keeps each screen focused rather than
/// presenting one intimidating form. Reference data (cities/areas/
/// relationship types) is a deterministic fixture in this presentation
/// phase; a real build fetches `GET /v1/reference-data/registration` once
/// and caches it in memory for the session, exactly as it will here.
class RegisterScreen extends StatefulWidget {
  const RegisterScreen({super.key, this.initialStep = 0});

  final int initialStep;

  @override
  State<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends State<RegisterScreen> {
  static const _stepCount = 3;
  late final _pageController = PageController(initialPage: widget.initialStep);
  late int _step = widget.initialStep;
  bool _isSubmitting = false;

  final _fullNameController = TextEditingController();
  final _emailController = TextEditingController();
  final _phoneController = TextEditingController();
  final _passwordController = TextEditingController();
  final _confirmController = TextEditingController();

  City? _city;
  ReferenceOption? _area;
  ReferenceOption? _relationshipType;
  final Set<ReferenceOption> _interests = {};

  @override
  void dispose() {
    _pageController.dispose();
    _fullNameController.dispose();
    _emailController.dispose();
    _phoneController.dispose();
    _passwordController.dispose();
    _confirmController.dispose();
    super.dispose();
  }

  bool get _step1Valid =>
      _fullNameController.text.trim().isNotEmpty &&
      _emailController.text.trim().contains('@') &&
      _phoneController.text.trim().length >= 8;

  bool get _passwordMeetsPolicy {
    final value = _passwordController.text;
    return value.length >= 8 &&
        RegExp(r'[A-Za-z]').hasMatch(value) &&
        RegExp(r'[0-9]').hasMatch(value);
  }

  bool get _step2Valid =>
      _passwordMeetsPolicy &&
      _confirmController.text == _passwordController.text;

  bool get _step3Valid =>
      _city != null && _area != null && _relationshipType != null;

  void _goToStep(int step) {
    setState(() => _step = step);
    _pageController.animateToPage(
      step,
      duration: const Duration(milliseconds: 260),
      curve: Curves.easeOutCubic,
    );
  }

  Future<void> _submit() async {
    setState(() => _isSubmitting = true);
    await Future<void>.delayed(const Duration(milliseconds: 700));
    if (!mounted) return;
    setState(() => _isSubmitting = false);
    context.pushReplacementNamed(
      'verifyPhoneOtp',
      extra: _phoneController.text.trim(),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        leading: BackButton(
          onPressed: () => _step == 0 ? context.pop() : _goToStep(_step - 1),
        ),
      ),
      body: SafeArea(
        child: Column(
          children: [
            Padding(
              padding: const EdgeInsets.symmetric(
                horizontal: BlueSpacing.pageGutter,
              ),
              child: _StepProgress(current: _step, total: _stepCount),
            ),
            Expanded(
              child: PageView(
                controller: _pageController,
                physics: const NeverScrollableScrollPhysics(),
                children: [
                  _IdentityStep(
                    fullNameController: _fullNameController,
                    emailController: _emailController,
                    phoneController: _phoneController,
                    isValid: _step1Valid,
                    onContinue: () => _goToStep(1),
                    onChanged: () => setState(() {}),
                  ),
                  _PasswordStep(
                    passwordController: _passwordController,
                    confirmController: _confirmController,
                    meetsPolicy: _passwordMeetsPolicy,
                    isValid: _step2Valid,
                    onContinue: () => _goToStep(2),
                    onChanged: () => setState(() {}),
                  ),
                  _HomeContextStep(
                    city: _city,
                    area: _area,
                    relationshipType: _relationshipType,
                    interests: _interests,
                    isValid: _step3Valid,
                    isSubmitting: _isSubmitting,
                    onPickCity: () async {
                      final selected = await showOptionPickerSheet<City>(
                        context,
                        title: 'Select city',
                        options: PreviewReferenceData.registration.cities,
                        labelOf: (c) => c.name,
                        selected: _city,
                      );
                      if (selected != null) {
                        setState(() {
                          _city = selected;
                          _area = null;
                        });
                      }
                    },
                    onPickArea: _city == null
                        ? null
                        : () async {
                            final selected =
                                await showOptionPickerSheet<ReferenceOption>(
                                  context,
                                  title: 'Select area',
                                  options: _city!.areas,
                                  labelOf: (a) => a.name,
                                  selected: _area,
                                );
                            if (selected != null) {
                              setState(() => _area = selected);
                            }
                          },
                    onPickRelationship: () async {
                      final selected =
                          await showOptionPickerSheet<ReferenceOption>(
                            context,
                            title: 'Your relationship to the property',
                            options: PreviewReferenceData
                                .registration
                                .propertyRelationshipTypes,
                            labelOf: (r) => r.name,
                            selected: _relationshipType,
                            searchable: false,
                          );
                      if (selected != null) {
                        setState(() => _relationshipType = selected);
                      }
                    },
                    onToggleInterest: (interest) => setState(() {
                      _interests.contains(interest)
                          ? _interests.remove(interest)
                          : _interests.add(interest);
                    }),
                    onSubmit: _submit,
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _StepProgress extends StatelessWidget {
  const _StepProgress({required this.current, required this.total});

  final int current;
  final int total;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: BlueSpacing.space12),
      child: Row(
        children: [
          for (var i = 0; i < total; i++) ...[
            if (i > 0) const SizedBox(width: BlueSpacing.space8),
            Expanded(
              child: ClipRRect(
                borderRadius: BlueRadii.pillRadius,
                child: LinearProgressIndicator(
                  value: i <= current ? 1 : 0,
                  minHeight: 4,
                  backgroundColor: BlueColors.surfaceMuted,
                  valueColor: const AlwaysStoppedAnimation(
                    BlueColors.brandPrimary,
                  ),
                ),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _IdentityStep extends StatelessWidget {
  const _IdentityStep({
    required this.fullNameController,
    required this.emailController,
    required this.phoneController,
    required this.isValid,
    required this.onContinue,
    required this.onChanged,
  });

  final TextEditingController fullNameController;
  final TextEditingController emailController;
  final TextEditingController phoneController;
  final bool isValid;
  final VoidCallback onContinue;
  final VoidCallback onChanged;

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.fromLTRB(
        BlueSpacing.pageGutter,
        BlueSpacing.space16,
        BlueSpacing.pageGutter,
        BlueSpacing.space24,
      ),
      children: [
        Text('Your details', style: BlueTypography.pageTitle),
        const SizedBox(height: BlueSpacing.space8),
        Text('Let\'s start with the basics.', style: BlueTypography.supporting),
        const SizedBox(height: BlueSpacing.space24),
        BlueTextField(
          controller: fullNameController,
          label: 'Full name',
          textCapitalization: TextCapitalization.words,
          autofillHints: const [AutofillHints.name],
          onChanged: (_) => onChanged(),
        ),
        const SizedBox(height: BlueSpacing.space16),
        BlueTextField(
          controller: emailController,
          label: 'Email',
          keyboardType: TextInputType.emailAddress,
          autofillHints: const [AutofillHints.email],
          onChanged: (_) => onChanged(),
        ),
        const SizedBox(height: BlueSpacing.space16),
        BlueTextField(
          controller: phoneController,
          label: 'Phone number',
          hint: '+971 5X XXX XXXX',
          keyboardType: TextInputType.phone,
          prefixIcon: Icons.call_outlined,
          autofillHints: const [AutofillHints.telephoneNumber],
          onChanged: (_) => onChanged(),
        ),
        const SizedBox(height: BlueSpacing.space32),
        PrimaryButton(
          label: 'Continue',
          onPressed: isValid ? onContinue : null,
        ),
      ],
    );
  }
}

class _PasswordStep extends StatelessWidget {
  const _PasswordStep({
    required this.passwordController,
    required this.confirmController,
    required this.meetsPolicy,
    required this.isValid,
    required this.onContinue,
    required this.onChanged,
  });

  final TextEditingController passwordController;
  final TextEditingController confirmController;
  final bool meetsPolicy;
  final bool isValid;
  final VoidCallback onContinue;
  final VoidCallback onChanged;

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.fromLTRB(
        BlueSpacing.pageGutter,
        BlueSpacing.space16,
        BlueSpacing.pageGutter,
        BlueSpacing.space24,
      ),
      children: [
        Text('Create a password', style: BlueTypography.pageTitle),
        const SizedBox(height: BlueSpacing.space8),
        Text(
          "You'll use this with your phone number to log in.",
          style: BlueTypography.supporting,
        ),
        const SizedBox(height: BlueSpacing.space24),
        BlueTextField(
          controller: passwordController,
          label: 'Password',
          isPassword: true,
          helperText: 'At least 8 characters, with a letter and a number.',
          onChanged: (_) => onChanged(),
        ),
        const SizedBox(height: BlueSpacing.space16),
        BlueTextField(
          controller: confirmController,
          label: 'Confirm password',
          isPassword: true,
          errorText:
              confirmController.text.isNotEmpty &&
                  confirmController.text != passwordController.text
              ? 'Passwords do not match.'
              : null,
          onChanged: (_) => onChanged(),
        ),
        const SizedBox(height: BlueSpacing.space32),
        PrimaryButton(
          label: 'Continue',
          onPressed: isValid ? onContinue : null,
        ),
      ],
    );
  }
}

class _HomeContextStep extends StatelessWidget {
  const _HomeContextStep({
    required this.city,
    required this.area,
    required this.relationshipType,
    required this.interests,
    required this.isValid,
    required this.isSubmitting,
    required this.onPickCity,
    required this.onPickArea,
    required this.onPickRelationship,
    required this.onToggleInterest,
    required this.onSubmit,
  });

  final City? city;
  final ReferenceOption? area;
  final ReferenceOption? relationshipType;
  final Set<ReferenceOption> interests;
  final bool isValid;
  final bool isSubmitting;
  final VoidCallback onPickCity;
  final VoidCallback? onPickArea;
  final VoidCallback onPickRelationship;
  final ValueChanged<ReferenceOption> onToggleInterest;
  final VoidCallback onSubmit;

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.fromLTRB(
        BlueSpacing.pageGutter,
        BlueSpacing.space16,
        BlueSpacing.pageGutter,
        BlueSpacing.space24,
      ),
      children: [
        Text('About your home', style: BlueTypography.pageTitle),
        const SizedBox(height: BlueSpacing.space8),
        Text(
          'This helps us show services available in your area.',
          style: BlueTypography.supporting,
        ),
        const SizedBox(height: BlueSpacing.space24),
        PickerField(label: 'City', value: city?.name, onTap: onPickCity),
        const SizedBox(height: BlueSpacing.space16),
        PickerField(
          label: 'Area',
          value: area?.name,
          onTap: onPickArea ?? () {},
          enabled: onPickArea != null,
        ),
        const SizedBox(height: BlueSpacing.space16),
        PickerField(
          label: 'Your relationship to the property',
          value: relationshipType?.name,
          onTap: onPickRelationship,
        ),
        const SizedBox(height: BlueSpacing.space20),
        Text('Service interests (optional)', style: BlueTypography.bodyStrong),
        const SizedBox(height: BlueSpacing.space8),
        Wrap(
          spacing: BlueSpacing.space8,
          runSpacing: BlueSpacing.space8,
          children: [
            for (final interest
                in PreviewReferenceData.registration.serviceCategories)
              BlueChoiceChip(
                label: interest.name,
                selected: interests.contains(interest),
                onTap: () => onToggleInterest(interest),
              ),
          ],
        ),
        const SizedBox(height: BlueSpacing.space32),
        PrimaryButton(
          label: 'Create account',
          isLoading: isSubmitting,
          onPressed: isValid ? onSubmit : null,
        ),
      ],
    );
  }
}

@Preview(name: 'Register - step 1', group: 'Auth', size: Size(390, 844))
Widget registerScreenStep1Preview() {
  return const RegisterScreen();
}

@Preview(name: 'Register - step 3', group: 'Auth', size: Size(390, 844))
Widget registerScreenStep3Preview() {
  return const RegisterScreen(initialStep: 2);
}
