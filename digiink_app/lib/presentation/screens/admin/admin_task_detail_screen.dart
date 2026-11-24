import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:digiink_app/core/constants/app_colors.dart';
import 'package:digiink_app/data/models/task_model.dart';
import 'package:digiink_app/data/services/api_service.dart';

class AdminTaskDetailScreen extends StatefulWidget {
  final int taskId;

  const AdminTaskDetailScreen({super.key, required this.taskId});

  @override
  State<AdminTaskDetailScreen> createState() => _AdminTaskDetailScreenState();
}

class _AdminTaskDetailScreenState extends State<AdminTaskDetailScreen> {
  bool _isLoading = true;
  TaskModel? _task;

  @override
  void initState() {
    super.initState();
    _loadTask();
  }

  Future<void> _loadTask() async {
    setState(() => _isLoading = true);
    final task = await ApiService().getTaskDetails(widget.taskId);
    if (!mounted) return;
    setState(() {
      _task = task;
      _isLoading = false;
    });
  }

  void _copyToClipboard(String text, String label) {
    Clipboard.setData(ClipboardData(text: text));
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text('$label copied to clipboard!'),
        backgroundColor: AppColors.primary,
        duration: const Duration(seconds: 2),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final task = _task;

    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: AppBar(
        title: Text(
          task != null ? task.taskKey : 'Task Details',
          style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16),
        ),
        actions: [
          if (task != null)
            IconButton(
              icon: const Icon(Icons.copy_rounded, size: 20),
              tooltip: 'Copy Task Key',
              onPressed: () => _copyToClipboard(task.taskKey, 'Task Key'),
            ),
          const SizedBox(width: 8),
        ],
      ),
      body: _isLoading || task == null
          ? const Center(child: CircularProgressIndicator(color: AppColors.primary))
          : SingleChildScrollView(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  // Header Card
                  _buildHeaderCard(task),

                  const SizedBox(height: 16),

                  // Key Metadata Grid
                  _buildMetadataGrid(task),

                  const SizedBox(height: 16),

                  // Original Instructions
                  _buildOriginalBriefCard(task),

                  // Highlighted Additional Instructions (if present)
                  if (task.hasAdditionalInstructions) ...[
                    const SizedBox(height: 16),
                    _buildAdditionalInstructionsCard(task),
                  ],

                  const SizedBox(height: 16),

                  // Google Drive Access Folders
                  _buildDriveFoldersCard(task),

                  // Client Feedback (if present)
                  if (task.hasFeedback) ...[
                    const SizedBox(height: 16),
                    _buildFeedbackCard(task),
                  ],

                  const SizedBox(height: 24),
                ],
              ),
            ),
    );
  }

  Widget _buildHeaderCard(TaskModel task) {
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(
          color: task.isUrgent ? AppColors.error.withOpacity(0.3) : AppColors.border,
          width: task.isUrgent ? 1.5 : 1,
        ),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                decoration: BoxDecoration(
                  color: AppColors.secondary,
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Text(
                  '#${task.id}',
                  style: const TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.w800),
                ),
              ),
              const SizedBox(width: 8),
              if (task.isUrgent) ...[
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                  decoration: BoxDecoration(
                    color: AppColors.errorLight,
                    borderRadius: BorderRadius.circular(8),
                    border: Border.all(color: AppColors.error.withOpacity(0.3)),
                  ),
                  child: const Row(
                    children: [
                      Icon(Icons.bolt_rounded, size: 14, color: AppColors.error),
                      SizedBox(width: 2),
                      Text('URGENT PRIORITY', style: TextStyle(fontSize: 10, fontWeight: FontWeight.w800, color: AppColors.error)),
                    ],
                  ),
                ),
                const SizedBox(width: 8),
              ],
              const Spacer(),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                decoration: BoxDecoration(
                  color: AppColors.primary.withOpacity(0.1),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Text(
                  task.status.replaceAll('_', ' ').toUpperCase(),
                  style: const TextStyle(fontSize: 10, fontWeight: FontWeight.w800, color: AppColors.primary),
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          Text(
            task.taskName,
            style: const TextStyle(
              fontSize: 17,
              fontWeight: FontWeight.w800,
              color: AppColors.textPrimary,
              height: 1.3,
            ),
          ),
          const SizedBox(height: 6),
          Text(
            'Client: ${task.clientName}',
            style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: AppColors.success),
          ),
        ],
      ),
    );
  }

  Widget _buildMetadataGrid(TaskModel task) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: AppColors.border),
      ),
      child: Column(
        children: [
          Row(
            children: [
              _buildMetaTile('DEADLINE', task.deadline, Icons.alarm_rounded, AppColors.error),
              _buildMetaTile('WORD COUNT', '${task.wordCount} Words', Icons.description_outlined, AppColors.textPrimary),
            ],
          ),
          const Padding(padding: EdgeInsets.symmetric(vertical: 8), child: Divider()),
          Row(
            children: [
              _buildMetaTile('SUBJECT', task.subject, Icons.school_outlined, AppColors.info),
              _buildMetaTile('UNIVERSITY / REGION', '${task.university} (${task.country})', Icons.public_rounded, AppColors.textSecondary),
            ],
          ),
          const Padding(padding: EdgeInsets.symmetric(vertical: 8), child: Divider()),
          Row(
            children: [
              _buildMetaTile('ASSIGNED EXPERT', task.expertName ?? 'Unassigned', Icons.person_pin_rounded, AppColors.roleTeam),
              _buildMetaTile('BDE CREATOR', task.bdeName ?? 'N/A', Icons.badge_outlined, AppColors.roleBDE),
            ],
          ),
        ],
      ),
    );
  }

  Widget _buildMetaTile(String label, String value, IconData icon, Color accent) {
    return Expanded(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, size: 16, color: accent),
          const SizedBox(width: 8),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  label,
                  style: const TextStyle(fontSize: 9, fontWeight: FontWeight.w800, color: AppColors.textMuted),
                ),
                const SizedBox(height: 2),
                Text(
                  value,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: AppColors.textPrimary),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildOriginalBriefCard(TaskModel task) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: AppColors.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Row(
            children: [
              Icon(Icons.notes_rounded, size: 16, color: AppColors.primary),
              SizedBox(width: 6),
              Text(
                'ORIGINAL CLIENT BRIEF',
                style: TextStyle(fontSize: 11, fontWeight: FontWeight.w800, color: AppColors.primary, letterSpacing: 0.8),
              ),
            ],
          ),
          const SizedBox(height: 10),
          Container(
            width: double.infinity,
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: AppColors.surfaceMuted,
              borderRadius: BorderRadius.circular(14),
            ),
            child: Text(
              task.instructions.isNotEmpty ? task.instructions : 'No original instructions provided.',
              style: const TextStyle(fontSize: 12, height: 1.5, color: AppColors.textSecondary, fontWeight: FontWeight.w500),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildAdditionalInstructionsCard(TaskModel task) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.warningLight,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: AppColors.warningBorder, width: 1.5),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              const Row(
                children: [
                  Icon(Icons.new_releases_rounded, size: 16, color: AppColors.warning),
                  SizedBox(width: 6),
                  Text(
                    'ADDITIONAL INFORMATION / CLIENT UPDATE',
                    style: TextStyle(
                      fontSize: 10,
                      fontWeight: FontWeight.w900,
                      color: Color(0xFF78350F),
                      letterSpacing: 0.5,
                    ),
                  ),
                ],
              ),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                decoration: BoxDecoration(
                  color: const Color(0xFFFDE68A),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: const Text(
                  'UPDATED',
                  style: TextStyle(fontSize: 9, fontWeight: FontWeight.w900, color: Color(0xFF78350F)),
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          Container(
            width: double.infinity,
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: AppColors.warningBorder.withOpacity(0.5)),
            ),
            child: Text(
              task.additionalInstructions,
              style: const TextStyle(
                fontSize: 12,
                height: 1.5,
                color: Color(0xFF451A03), // very dark amber/slate
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildDriveFoldersCard(TaskModel task) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: AppColors.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Row(
            children: [
              Icon(Icons.cloud_outlined, size: 16, color: AppColors.info),
              SizedBox(width: 6),
              Text(
                'GOOGLE DRIVE CLOUD WORKSPACES',
                style: TextStyle(fontSize: 11, fontWeight: FontWeight.w800, color: AppColors.textSecondary, letterSpacing: 0.8),
              ),
            ],
          ),
          const SizedBox(height: 12),
          Row(
            children: [
              Expanded(
                child: _buildDriveBtn(
                  'Client Files',
                  Icons.folder_shared_rounded,
                  AppColors.primary,
                  task.driveClientLink,
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: _buildDriveBtn(
                  'Operations Files',
                  Icons.inventory_2_rounded,
                  AppColors.success,
                  task.driveOperationsLink,
                ),
              ),
            ],
          ),
          if (task.driveAdditionalLink != null && task.driveAdditionalLink!.isNotEmpty) ...[
            const SizedBox(height: 10),
            _buildDriveBtn(
              'Additional Materials (Extras)',
              Icons.attach_file_rounded,
              AppColors.warning,
              task.driveAdditionalLink,
            ),
          ],
        ],
      ),
    );
  }

  Widget _buildDriveBtn(String title, IconData icon, Color color, String? link) {
    final hasLink = link != null && link.isNotEmpty;
    return InkWell(
      onTap: hasLink
          ? () {
              ScaffoldMessenger.of(context).showSnackBar(
                SnackBar(content: Text('Drive Link: $link')),
              );
            }
          : null,
      borderRadius: BorderRadius.circular(12),
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 10),
        decoration: BoxDecoration(
          color: hasLink ? color.withOpacity(0.08) : AppColors.surfaceMuted,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(
            color: hasLink ? color.withOpacity(0.3) : AppColors.border,
          ),
        ),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(icon, size: 16, color: hasLink ? color : AppColors.textMuted),
            const SizedBox(width: 6),
            Flexible(
              child: Text(
                title,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                  fontSize: 11,
                  fontWeight: FontWeight.w700,
                  color: hasLink ? color : AppColors.textMuted,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildFeedbackCard(TaskModel task) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppColors.surface,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: AppColors.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Row(
            children: [
              Icon(Icons.rate_review_rounded, size: 16, color: AppColors.info),
              SizedBox(width: 6),
              Text(
                'CLIENT FEEDBACK & SCREENSHOTS',
                style: TextStyle(fontSize: 11, fontWeight: FontWeight.w800, color: AppColors.info, letterSpacing: 0.8),
              ),
            ],
          ),
          const SizedBox(height: 10),
          if (task.feedback != null && task.feedback!.isNotEmpty)
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: AppColors.surfaceMuted,
                borderRadius: BorderRadius.circular(14),
              ),
              child: Text(
                task.feedback!,
                style: const TextStyle(fontSize: 12, height: 1.5, color: AppColors.textPrimary, fontStyle: FontStyle.italic),
              ),
            ),
        ],
      ),
    );
  }
}

