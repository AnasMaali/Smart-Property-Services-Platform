import 'package:flutter/material.dart';

import '../../../../app/theme/blue_theme.dart';
import '../../../../core/input/latin_digits.dart';
import 'blue_sheet.dart';
import 'palestine_flag.dart';
import 'uae_flag.dart';

class BlueCountry {
  const BlueCountry({
    required this.name,
    required this.dial,
    required this.iso,
  });

  final String name;
  final String dial;
  final String iso;

  static const uae = BlueCountry(
    name: 'United Arab Emirates',
    dial: '+971',
    iso: 'AE',
  );

  static const all = <BlueCountry>[
    uae,
    BlueCountry(name: 'Saudi Arabia', dial: '+966', iso: 'SA'),
    BlueCountry(name: 'Qatar', dial: '+974', iso: 'QA'),
    BlueCountry(name: 'Kuwait', dial: '+965', iso: 'KW'),
    BlueCountry(name: 'Bahrain', dial: '+973', iso: 'BH'),
    BlueCountry(name: 'Oman', dial: '+968', iso: 'OM'),
    BlueCountry(name: 'Egypt', dial: '+20', iso: 'EG'),
    BlueCountry(name: 'Jordan', dial: '+962', iso: 'JO'),
    BlueCountry(name: 'Palestine', dial: '+970', iso: 'PS'),
    BlueCountry(name: 'India', dial: '+91', iso: 'IN'),
    BlueCountry(name: 'Pakistan', dial: '+92', iso: 'PK'),
    BlueCountry(name: 'United Kingdom', dial: '+44', iso: 'GB'),
    BlueCountry(name: 'United States', dial: '+1', iso: 'US'),
  ];
}

class BlueCountryFlag extends StatelessWidget {
  const BlueCountryFlag({super.key, required this.iso});

  final String iso;

  static String emoji(String iso) {
    final code = iso.toUpperCase();
    return String.fromCharCodes(code.codeUnits.map((c) => 0x1F1E6 + c - 0x41));
  }

  @override
  Widget build(BuildContext context) {
    if (iso == 'AE') return const UaeFlag();
    if (iso == 'PS') return const PalestineFlag();
    return SizedBox(
      width: 22,
      height: 15,
      child: FittedBox(
        child: Text(
          emoji(iso),
          style: const TextStyle(fontSize: 16, height: 1),
        ),
      ),
    );
  }
}

class BlueCountrySheet extends StatefulWidget {
  const BlueCountrySheet({
    super.key,
    required this.selected,
    required this.onPicked,
    required this.onClose,
  });

  final BlueCountry selected;
  final ValueChanged<BlueCountry> onPicked;
  final VoidCallback onClose;

  @override
  State<BlueCountrySheet> createState() => _BlueCountrySheetState();
}

class _BlueCountrySheetState extends State<BlueCountrySheet> {
  final _search = TextEditingController();
  String _query = '';

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  List<BlueCountry> get _filtered {
    final q = _query.trim().toLowerCase();
    if (q.isEmpty) return BlueCountry.all;
    return BlueCountry.all.where((c) {
      return c.name.toLowerCase().contains(q) ||
          c.dial.contains(q) ||
          c.iso.toLowerCase().contains(q) ||
          (c.iso == 'PS' && 'فلسطين'.contains(q));
    }).toList();
  }

  @override
  Widget build(BuildContext context) {
    final options = _filtered;
    return BlueSheetPanel(
      title: 'Select country',
      onClose: widget.onClose,
      header: Padding(
        padding: const EdgeInsets.fromLTRB(20, 6, 20, 12),
        child: Container(
          height: 50,
          padding: const EdgeInsets.symmetric(horizontal: 14),
          decoration: BoxDecoration(
            color: BlueColors.canvas,
            borderRadius: BorderRadius.circular(16),
            border: Border.all(color: BlueColors.border),
          ),
          child: Row(
            children: [
              const CustomPaint(size: Size(16, 16), painter: _SearchPainter()),
              const SizedBox(width: 10),
              Expanded(
                child: TextField(
                  controller: _search,
                  onChanged: (v) => setState(() => _query = v),
                  inputFormatters: const [LatinDigits.formatter],
                  cursorColor: BlueColors.ink,
                  style: const TextStyle(
                    fontFamily: BlueFonts.jakarta,
                    fontSize: 15.5,
                    fontWeight: FontWeight.w500,
                    color: BlueColors.ink,
                  ),
                  decoration: const InputDecoration(
                    isCollapsed: true,
                    border: InputBorder.none,
                    hintText: 'Search countries',
                    hintStyle: TextStyle(
                      fontFamily: BlueFonts.jakarta,
                      fontSize: 15.5,
                      fontWeight: FontWeight.w500,
                      color: BlueColors.placeholder,
                    ),
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
      child: ListView.builder(
        padding: const EdgeInsets.fromLTRB(12, 0, 12, 8),
        shrinkWrap: true,
        itemCount: options.isEmpty ? 1 : options.length,
        itemBuilder: (context, index) {
          if (options.isEmpty) {
            return Padding(
              padding: const EdgeInsets.fromLTRB(12, 22, 12, 0),
              child: Text(
                'No match for "$_query". Check the spelling or pick from the list.',
                style: const TextStyle(
                  fontFamily: BlueFonts.jakarta,
                  fontSize: 14,
                  height: 1.5,
                  fontWeight: FontWeight.w400,
                  color: BlueColors.muted,
                ),
              ),
            );
          }
          final country = options[index];
          return BlueSheetRow(
            index: index,
            label: '${country.name}  ${country.dial}',
            selected: country.iso == widget.selected.iso,
            leading: BlueCountryFlag(iso: country.iso),
            onPressed: () => widget.onPicked(country),
          );
        },
      ),
    );
  }
}

class _SearchPainter extends CustomPainter {
  const _SearchPainter();

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = BlueColors.chevron
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2.2 * (size.width / 24)
      ..strokeCap = StrokeCap.round;
    final sx = size.width / 24;
    final sy = size.height / 24;
    canvas.drawCircle(Offset(11 * sx, 11 * sy), 6.5 * sx, paint);
    canvas.drawLine(
      Offset(16 * sx, 16 * sy),
      Offset(20.5 * sx, 20.5 * sy),
      paint,
    );
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}
