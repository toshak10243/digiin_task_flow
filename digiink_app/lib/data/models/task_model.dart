class TaskModel {
  final int id;
  final String taskKey;
  final String taskName;
  final String clientName;
  final String deadline;
  final int wordCount;
  final String university;
  final String country;
  final String subject;
  final String instructions;
  final String additionalInstructions;
  final String urgent;
  final String status;
  final String currentStage;
  final String? expertName;
  final String? bdeName;
  final String? driveClientLink;
  final String? driveOperationsLink;
  final String? driveAdditionalLink;
  final String? teamNotes;
  final String? feedback;
  final List<dynamic> feedbackFiles;
  final String createdAt;

  TaskModel({
    required this.id,
    required this.taskKey,
    required this.taskName,
    required this.clientName,
    required this.deadline,
    required this.wordCount,
    required this.university,
    required this.country,
    required this.subject,
    required this.instructions,
    this.additionalInstructions = '',
    required this.urgent,
    required this.status,
    required this.currentStage,
    this.expertName,
    this.bdeName,
    this.driveClientLink,
    this.driveOperationsLink,
    this.driveAdditionalLink,
    this.teamNotes,
    this.feedback,
    this.feedbackFiles = const [],
    required this.createdAt,
  });

  bool get isUrgent => urgent.toLowerCase() == 'yes';
  bool get hasAdditionalInstructions => additionalInstructions.trim().isNotEmpty;
  bool get hasFeedback => (feedback != null && feedback!.trim().isNotEmpty) || feedbackFiles.isNotEmpty;

  factory TaskModel.fromJson(Map<String, dynamic> json) {
    return TaskModel(
      id: json['id'] is int ? json['id'] : int.tryParse(json['id'].toString()) ?? 0,
      taskKey: json['task_key'] ?? '',
      taskName: json['task_name'] ?? '',
      clientName: json['client_name'] ?? 'N/A',
      deadline: json['deadline'] ?? '',
      wordCount: json['word_count'] is int ? json['word_count'] : int.tryParse(json['word_count']?.toString() ?? '0') ?? 0,
      university: json['university'] ?? 'N/A',
      country: json['country'] ?? 'N/A',
      subject: json['subject'] ?? 'General',
      instructions: json['instructions'] ?? '',
      additionalInstructions: json['additional_instructions'] ?? '',
      urgent: json['urgent'] ?? 'no',
      status: json['status'] ?? 'new',
      currentStage: json['current_stage'] ?? 'operations_manager',
      expertName: json['expert_name'] ?? json['team_name'],
      bdeName: json['bde_name'],
      driveClientLink: json['drive_client_link'] ?? json['drive_folder_link'],
      driveOperationsLink: json['drive_operations_link'],
      driveAdditionalLink: json['drive_additional_link'] ?? json['additional_files'],
      teamNotes: json['team_notes'],
      feedback: json['feedback'],
      feedbackFiles: json['feedback_files_parsed'] ?? [],
      createdAt: json['created_at'] ?? json['task_created_at'] ?? '',
    );
  }
}

