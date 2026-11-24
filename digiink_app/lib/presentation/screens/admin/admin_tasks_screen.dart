import 'dart:async';
import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:digiink_app/core/constants/app_colors.dart';
import 'package:digiink_app/data/models/task_model.dart';
import 'package:digiink_app/data/services/api_service.dart';
import 'package:digiink_app/presentation/widgets/task_card.dart';
import 'package:digiink_app/presentation/screens/admin/admin_task_detail_screen.dart';

class AdminTasksScreen extends StatefulWidget {
  const AdminTasksScreen({super.key});

  @override
  State<AdminTasksScreen> createState() => _AdminTasksScreenState();
}

class _AdminTasksScreenState extends State<AdminTasksScreen> {
  final _searchController = TextEditingController();
  Timer? _debounceTimer;
  bool _isLoading = true;
  String _selectedStatus = 'all';
  DateTime? _selectedDate;
  List<TaskModel> _tasks = [];

  final List<Map<String, String>> _statusFilters = const [
    {'label': 'All', 'value': 'all'},
    {'label': 'Urgent', 'value': 'urgent'},
    {'label': 'Working', 'value': 'in_progress'},
    {'label': 'Assigned', 'value': 'assigned'},
    {'label': 'Completed', 'value': 'completed'},
    {'label': 'Rework', 'value': 'rework'},
  ];

  @override
  void initState() {
    super.initState();
    _fetchTasks();
  }

  @override
  void dispose() {
    _debounceTimer?.cancel();
    _searchController.dispose();
    super.dispose();
  }

  void _onSearchChanged(String _) {
    _debounceTimer?.cancel();
    _debounceTimer = Timer(const Duration(milliseconds: 200), () {
      _fetchTasks();
    });
  }

  Future<void> _fetchTasks() async {
    setState(() => _isLoading = true);
    final dateStr = _selectedDate != null ? DateFormat('yyyy-MM-dd').format(_selectedDate!) : 'all';
    final result = await ApiService().getTasks(
      date: dateStr,
      status: _selectedStatus,
      search: _searchController.text.trim(),
    );

    if (!mounted) return;
    setState(() {
      _tasks = result;
      _isLoading = false;
    });
  }

  Future<void> _pickDate() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _selectedDate ?? DateTime.now(),
      firstDate: DateTime(2024),
      lastDate: DateTime(2030),
    );

    if (picked != null) {
      setState(() => _selectedDate = picked);
      _fetchTasks();
    }
  }

  void _clearDateFilter() {
    setState(() => _selectedDate = null);
    _fetchTasks();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: AppBar(
        title: const Text(
          'Task Inventory',
          style: TextStyle(fontWeight: FontWeight.w800, fontSize: 18),
        ),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh_rounded),
            onPressed: _fetchTasks,
          ),
          const SizedBox(width: 8),
        ],
      ),
      body: Column(
        children: [
          // Search & Date Filter Header
          Container(
            color: AppColors.surface,
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
            child: Column(
              children: [
                // Search Field
                TextField(
                  controller: _searchController,
                  onChanged: _onSearchChanged,
                  onSubmitted: (_) => _fetchTasks(),
                  decoration: InputDecoration(
                    hintText: 'Search task key, name, client, expert...',
                    prefixIcon: const Icon(Icons.search_rounded, size: 20, color: AppColors.textMuted),
                    suffixIcon: _searchController.text.isNotEmpty
                        ? IconButton(
                            icon: const Icon(Icons.clear_rounded, size: 18),
                            onPressed: () {
                              _searchController.clear();
                              _fetchTasks();
                            },
                          )
                        : null,
                  ),
                ),
                const SizedBox(height: 10),

                // Date Filter Bar
                Row(
                  children: [
                    InkWell(
                      onTap: _pickDate,
                      borderRadius: BorderRadius.circular(10),
                      child: Container(
                        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                        decoration: BoxDecoration(
                          color: _selectedDate != null ? AppColors.primaryLight : AppColors.surfaceMuted,
                          borderRadius: BorderRadius.circular(10),
                          border: Border.all(
                            color: _selectedDate != null ? AppColors.primary.withOpacity(0.3) : AppColors.border,
                          ),
                        ),
                        child: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Icon(
                              Icons.calendar_month_rounded,
                              size: 14,
                              color: _selectedDate != null ? AppColors.primary : AppColors.textSecondary,
                            ),
                            const SizedBox(width: 6),
                            Text(
                              _selectedDate != null
                                  ? DateFormat('dd MMM yyyy').format(_selectedDate!)
                                  : 'Filter by Date',
                              style: TextStyle(
                                fontSize: 11,
                                fontWeight: FontWeight.w700,
                                color: _selectedDate != null ? AppColors.primary : AppColors.textSecondary,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                    if (_selectedDate != null) ...[
                      const SizedBox(width: 6),
                      IconButton(
                        icon: const Icon(Icons.close_rounded, size: 16, color: AppColors.textMuted),
                        padding: EdgeInsets.zero,
                        constraints: const BoxConstraints(),
                        onPressed: _clearDateFilter,
                      ),
                    ],
                    const Spacer(),
                    Text(
                      '${_tasks.length} tasks',
                      style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w700, color: AppColors.textMuted),
                    ),
                  ],
                ),
                const SizedBox(height: 10),

                // Status Pills
                SizedBox(
                  height: 32,
                  child: ListView.separated(
                    scrollDirection: Axis.horizontal,
                    itemCount: _statusFilters.length,
                    separatorBuilder: (_, __) => const SizedBox(width: 8),
                    itemBuilder: (ctx, i) {
                      final item = _statusFilters[i];
                      final isSelected = _selectedStatus == item['value'];
                      return ChoiceChip(
                        label: Text(
                          item['label']!,
                          style: TextStyle(
                            fontSize: 11,
                            fontWeight: FontWeight.w700,
                            color: isSelected ? Colors.white : AppColors.textSecondary,
                          ),
                        ),
                        selected: isSelected,
                        selectedColor: AppColors.primary,
                        backgroundColor: AppColors.surfaceMuted,
                        showCheckmark: false,
                        padding: const EdgeInsets.symmetric(horizontal: 10),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                        side: BorderSide(
                          color: isSelected ? AppColors.primary : AppColors.border,
                        ),
                        onSelected: (val) {
                          if (val) {
                            setState(() => _selectedStatus = item['value']!);
                            _fetchTasks();
                          }
                        },
                      );
                    },
                  ),
                ),
              ],
            ),
          ),

          // Tasks List
          Expanded(
            child: _isLoading
                ? const Center(child: CircularProgressIndicator(color: AppColors.primary))
                : _tasks.isEmpty
                    ? Center(
                        child: Column(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            Container(
                              padding: const EdgeInsets.all(20),
                              decoration: BoxDecoration(
                                color: AppColors.surfaceMuted,
                                borderRadius: BorderRadius.circular(30),
                              ),
                              child: const Icon(Icons.assignment_late_outlined, size: 44, color: AppColors.textMuted),
                            ),
                            const SizedBox(height: 14),
                            const Text(
                              'No Tasks Found',
                              style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold, color: AppColors.textPrimary),
                            ),
                            const SizedBox(height: 4),
                            const Text(
                              'Try adjusting your search or filters',
                              style: TextStyle(fontSize: 12, color: AppColors.textMuted),
                            ),
                          ],
                        ),
                      )
                    : RefreshIndicator(
                        onRefresh: _fetchTasks,
                        color: AppColors.primary,
                        child: ListView.separated(
                          padding: const EdgeInsets.all(16),
                          itemCount: _tasks.length,
                          separatorBuilder: (_, __) => const SizedBox(height: 12),
                          itemBuilder: (ctx, index) {
                            final task = _tasks[index];
                            return TaskCard(
                              task: task,
                              onTap: () {
                                Navigator.push(
                                  context,
                                  MaterialPageRoute(
                                    builder: (_) => AdminTaskDetailScreen(taskId: task.id),
                                  ),
                                );
                              },
                            );
                          },
                        ),
                      ),
          ),
        ],
      ),
    );
  }
}

