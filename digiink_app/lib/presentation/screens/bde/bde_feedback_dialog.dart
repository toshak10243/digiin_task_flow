import 'package:flutter/material.dart';
import 'package:digiink_app/core/constants/app_colors.dart';
import 'package:digiink_app/data/models/task_model.dart';
import 'package:digiink_app/data/services/api_service.dart';

class BdeFeedbackDialog extends StatefulWidget {
  final TaskModel task;
  final VoidCallback? onSubmitted;

  const BdeFeedbackDialog({
    super.key,
    required this.task,
    this.onSubmitted,
  });

  static Future<void> show(BuildContext context, TaskModel task, {VoidCallback? onSubmitted}) {
    return showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) => BdeFeedbackDialog(task: task, onSubmitted: onSubmitted),
    );
  }

  @override
  State<BdeFeedbackDialog> createState() => _BdeFeedbackDialogState();
}

class _BdeFeedbackDialogState extends State<BdeFeedbackDialog> {
  final ApiService _apiService = ApiService();
  final _feedbackController = TextEditingController();
  bool _isRework = true;
  bool _isSubmitting = false;

  @override
  void dispose() {
    _feedbackController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final text = _feedbackController.text.trim();
    if (text.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Please enter feedback or rework details'),
          backgroundColor: AppColors.error,
        ),
      );
      return;
    }

    setState(() => _isSubmitting = true);

    final res = await _apiService.submitFeedback(
      taskId: widget.task.id,
      feedback: text,
      isRework: _isRework,
    );

    if (!mounted) return;
    setState(() => _isSubmitting = false);

    if (res['success'] == true) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(res['message'] ?? 'Feedback submitted successfully!'),
          backgroundColor: AppColors.success,
        ),
      );
      widget.onSubmitted?.call();
      Navigator.pop(context);
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(res['message'] ?? 'Submission failed'),
          backgroundColor: AppColors.error,
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = widget.task;

    return Container(
      padding: EdgeInsets.only(
        top: 20,
        left: 20,
        right: 20,
        bottom: MediaQuery.of(context).viewInsets.bottom + 20,
      ),
      decoration: const BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      child: SingleChildScrollView(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            // Handle bar
            Center(
              child: Container(
                width: 40,
                height: 4,
                decoration: BoxDecoration(
                  color: AppColors.border,
                  borderRadius: BorderRadius.circular(2),
                ),
              ),
            ),
            const SizedBox(height: 16),

            // Header
            Row(
              children: [
                Container(
                  width: 36,
                  height: 36,
                  decoration: BoxDecoration(
                    color: _isRework ? const Color(0xFFFDE8EC) : const Color(0xFFE8EDFE),
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: Icon(
                    _isRework ? Icons.autorenew_rounded : Icons.feedback_outlined,
                    size: 20,
                    color: _isRework ? const Color(0xFFE11D48) : const Color(0xFF3B6CF6),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        _isRework ? 'Submit Rework Request' : 'Submit Client Feedback',
                        style: const TextStyle(fontSize: 15, fontWeight: FontWeight.bold, color: AppColors.textPrimary),
                      ),
                      Text(
                        'For Task ${t.taskKey}',
                        style: const TextStyle(fontSize: 11, color: AppColors.textMuted),
                      ),
                    ],
                  ),
                ),
                IconButton(
                  icon: const Icon(Icons.close_rounded, size: 20, color: AppColors.textMuted),
                  onPressed: () => Navigator.pop(context),
                ),
              ],
            ),
            const SizedBox(height: 16),
            const Divider(height: 1, color: AppColors.border),
            const SizedBox(height: 16),

            // Rework Toggle
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
              decoration: BoxDecoration(
                color: _isRework ? const Color(0xFFFDE8EC) : AppColors.surface,
                borderRadius: BorderRadius.circular(8),
                border: Border.all(color: _isRework ? const Color(0xFFFDA4AF) : AppColors.border),
              ),
              child: Row(
                children: [
                  Icon(
                    Icons.replay_circle_filled_rounded,
                    size: 20,
                    color: _isRework ? const Color(0xFFE11D48) : AppColors.textMuted,
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'Mark as Rework (Return to Team)',
                          style: TextStyle(
                            fontSize: 12,
                            fontWeight: FontWeight.bold,
                            color: _isRework ? const Color(0xFF9F1239) : AppColors.textPrimary,
                          ),
                        ),
                        const Text(
                          'Sets task status back to Rework and alerts OM',
                          style: TextStyle(fontSize: 10, color: AppColors.textMuted),
                        ),
                      ],
                    ),
                  ),
                  Switch(
                    value: _isRework,
                    onChanged: (val) => setState(() => _isRework = val),
                    activeColor: const Color(0xFFE11D48),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 14),

            // Feedback Notes
            const Text(
              'Client Feedback & Modification Points *',
              style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: AppColors.textPrimary),
            ),
            const SizedBox(height: 6),
            TextField(
              controller: _feedbackController,
              maxLines: 5,
              decoration: const InputDecoration(
                hintText: 'Enter specific corrections requested by client, professor comments, missing sections, or formatting fixes...',
              ),
            ),
            const SizedBox(height: 20),

            // Action Buttons
            Row(
              children: [
                Expanded(
                  child: OutlinedButton(
                    onPressed: () => Navigator.pop(context),
                    style: OutlinedButton.styleFrom(
                      padding: const EdgeInsets.symmetric(vertical: 12),
                      side: const BorderSide(color: AppColors.border),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                    ),
                    child: const Text('Cancel', style: TextStyle(color: AppColors.textSecondary, fontWeight: FontWeight.bold)),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  flex: 2,
                  child: ElevatedButton.icon(
                    onPressed: _isSubmitting ? null : _submit,
                    icon: _isSubmitting
                        ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2))
                        : Icon(_isRework ? Icons.replay_rounded : Icons.send_rounded, size: 16, color: Colors.white),
                    label: Text(
                      _isSubmitting
                          ? 'Submitting...'
                          : _isRework
                              ? 'Dispatch Rework'
                              : 'Send Feedback',
                      style: const TextStyle(fontWeight: FontWeight.bold, color: Colors.white),
                    ),
                    style: ElevatedButton.styleFrom(
                      backgroundColor: _isRework ? const Color(0xFFE11D48) : AppColors.primary,
                      padding: const EdgeInsets.symmetric(vertical: 12),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                      elevation: 0,
                    ),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

