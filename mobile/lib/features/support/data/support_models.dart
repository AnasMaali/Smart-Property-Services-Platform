enum SupportStatus { open, inProgress, resolved }

class SupportMessage {
  const SupportMessage({
    required this.fromSupport,
    required this.time,
    required this.text,
  });

  final bool fromSupport;
  final String time;
  final String text;

  String get author => fromSupport ? 'BLUE Support' : 'You';
}

class SupportRequest {
  const SupportRequest({
    required this.id,
    required this.number,
    required this.subject,
    required this.status,
    required this.listMeta,
    required this.openedLabel,
    required this.messages,
  });

  final String id;
  final String number;
  final String subject;
  final SupportStatus status;
  final String listMeta;
  final String openedLabel;
  final List<SupportMessage> messages;

  String get statusLabel => switch (status) {
    SupportStatus.open => 'Open',
    SupportStatus.inProgress => 'In progress',
    SupportStatus.resolved => 'Resolved',
  };

  SupportRequest copyWith({
    SupportStatus? status,
    String? listMeta,
    List<SupportMessage>? messages,
  }) {
    return SupportRequest(
      id: id,
      number: number,
      subject: subject,
      status: status ?? this.status,
      listMeta: listMeta ?? this.listMeta,
      openedLabel: openedLabel,
      messages: messages ?? this.messages,
    );
  }
}

String blueSupportDay(DateTime date) {
  const months = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec',
  ];
  return '${date.day} ${months[date.month - 1]} ${date.year}';
}
