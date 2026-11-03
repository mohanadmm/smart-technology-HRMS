<?php

declare(strict_types=1);

return [
    'validation_failed' => 'البيانات المُرسلة غير صحيحة.',
    'not_found' => 'المورد غير موجود.',
    'unauthenticated' => 'يرجى تسجيل الدخول.',
    'server_error' => 'حدث خطأ غير متوقع. يرجى المحاولة مرة أخرى.',
    'bank_account_required' => 'رقم الحساب البنكي مطلوب عند صرف المرتب بتحويل بنكي.',
    'pay_field_required' => 'حقل :field مطلوب لنوع الموظف :type.',
    'no_type_on_date' => 'لا يوجد نوع ساري للموظف في هذا التاريخ.',
    'duplicate_date' => 'يوجد سجل آخر بنفس التاريخ.',
    'not_a_worker' => 'الموقع الأساسي للعمال فقط، والموظف ليس عاملاً في هذا التاريخ.',
    'no_previous_site' => 'لا يوجد موقع سابق للعودة إليه.',
    'later_move_inside' => 'يوجد نقل آخر بين تاريخ النقل وتاريخ العودة.',
    'leave_code_required' => 'كود الإجازة مطلوب لأنواع الإجازات.',
    'leave_code_not_allowed' => 'كود الإجازة يخص أنواع الإجازات فقط.',
    'leave_code_taken' => 'يوجد نوع إجازة نشط آخر بنفس الكود.',
    'code_taken' => 'الرقم الوظيفي :code مستخدم للموظف :name.',
    'code_deleted_holder' => 'هذا الرقم الوظيفي كان لموظف محذوف، ولا يُعاد استخدام أرقام الموظفين المحذوفين.',
    'code_changed_meanwhile' => 'تم استخدام هذا الرقم الوظيفي للتو. يرجى اختيار رقم آخر.',
    'allowance_value_required' => 'القيمة مطلوبة ما لم يكن نوع البدل "بدون بدل".',
    'employee_type' => [
        'office' => 'موظف مثبت شركة',
        'engineer' => 'مهندس',
        'permanent_worker' => 'عامل مثبت',
        'day_laborer' => 'عامل باليومية',
    ],
];
