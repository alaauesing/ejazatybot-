<?php
// التوكن الخاص بالبوت
$token = '7841668520:AAGUs3OaFl_9YU_saRLqcxLaURnWjWFDAmM';
$api_url = "https://api.telegram.org/bot$token/";

// تخزين الرصيد وسجل الإجازات
$usersData = []; // سيحفظ بيانات المستخدمين (رصيدهم وسجل الإجازات)

// قراءة البيانات من ملف (لتخزين واسترجاع البيانات)
function loadData() {
    global $usersData;
    if (file_exists('data.json')) {
        $usersData = json_decode(file_get_contents('data.json'), true);
    }
}

// حفظ البيانات إلى ملف
function saveData() {
    global $usersData;
    file_put_contents('data.json', json_encode($usersData));
}

// لوحة المفاتيح الثابتة
$keyboard = [
    [['text' => "عرض الرصيد"], ['text' => "تعديل الرصيد"]],
    [['text' => "استقطاع اجازة"], ['text' => "سجل الإجازات"]],
    [['text' => "مسح البيانات"]] // إضافة الزر الجديد لمسح البيانات
];

// دالة لتحويل الأرقام العربية إلى الإنجليزية
function convertArabicNumbers($text) {
    $arabic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    return str_replace($arabic, $english, $text);
}

// الدالة لإرسال الرسائل
function sendMessage($chatId, $message, $keyboard = null) {
    global $api_url;
    $data = [
        'chat_id' => $chatId,
        'text' => $message,
        'reply_markup' => $keyboard ? json_encode(['keyboard' => $keyboard, 'resize_keyboard' => true, 'one_time_keyboard' => false]) : null
    ];
    file_get_contents($api_url . "sendMessage?" . http_build_query($data));
}

// قراءة البيانات من Telegram
$update = json_decode(file_get_contents('php://input'), true);

if ($update) {
    loadData(); // تحميل البيانات من الملف

    // إذا كان التحديث من نوع my_chat_member
    if (isset($update['my_chat_member'])) {
        $chatId = $update['my_chat_member']['chat']['id'];
        $status = $update['my_chat_member']['new_chat_member']['status'];

        // التحقق إذا غادر المستخدم أو حظر البوت
        if ($status === 'kicked' || $status === 'left') {
            unset($usersData[$chatId]); // حذف بيانات المستخدم
            saveData();
        }
    } elseif (isset($update['message'])) {
        $chatId = $update['message']['chat']['id'];
        $text = $update['message']['text'];

        // تحويل الأرقام العربية إلى الإنجليزية
        $text = convertArabicNumbers($text);

        // التعامل مع الرسائل الواردة
        if ($text == "/start") {
            sendMessage($chatId, 'مرحباً.. قم بإدخال رصيد إجازاتك الحالي (مثلاً: 10)');
            $usersData[$chatId]['state'] = 'awaiting_balance';  // تحديد حالة المستخدم
            saveData();
        } else {
            // إذا كان الرصيد غير معروف (لم يتم إدخاله بعد)
            if (!isset($usersData[$chatId]['balance'])) {
                // التحقق من أن النص المدخل هو رقم صالح
                if (is_numeric($text) && $text > 0) {
                    $usersData[$chatId]['balance'] = (int)$text;
                    $usersData[$chatId]['vacationLog'] = []; // تهيئة سجل الإجازات
                    $usersData[$chatId]['state'] = 'none';  // إعادة تعيين الحالة
                    saveData();
                    sendMessage($chatId, "تم تسجيل رصيد إجازاتك: $text يوم.", $keyboard);
                } else {
                    sendMessage($chatId, 'يرجى إدخال رقم صالح.');
                }
            } else {
                // التعامل مع الأزرار
                switch ($text) {
                    case "عرض الرصيد":
                        $balance = $usersData[$chatId]['balance'];
                        sendMessage($chatId, "رصيدك الحالي هو: $balance يوم.", $keyboard);
                        break;

                    case "تعديل الرصيد":
                        $usersData[$chatId]['state'] = 'updating_balance';  // تحديد الحالة لتعديل الرصيد
                        saveData();
                        sendMessage($chatId, 'أرسل عدد الرصيد بعد التحديث');
                        break;

                    case "استقطاع اجازة":
                        $usersData[$chatId]['state'] = 'deducting_vacation';  // تحديد الحالة لاستقطاع الإجازة
                        saveData();
                        sendMessage($chatId, 'أدخل عدد أيام الإجازة؟');
                        break;

                    case "سجل الإجازات":
                        if (empty($usersData[$chatId]['vacationLog'])) {
                            sendMessage($chatId, 'لا توجد إجازات مستقطعة في السجل.', $keyboard);
                        } else {
                            $logMessage = "*سجل الإجازات*\n\n"; // إضافة العنوان بخط عريض
                            foreach ($usersData[$chatId]['vacationLog'] as $entry) {
                                $logMessage .= "* إجازة " . $entry['days'] . " أيام من " . $entry['start_date'] . " إلى " . $entry['end_date'] . "\n";
                            }
                            sendMessage($chatId, $logMessage, $keyboard);
                        }
                        break;

                    case "مسح البيانات": // إذا تم الضغط على زر مسح البيانات
                        unset($usersData[$chatId]); // مسح بيانات المستخدم
                        saveData();
                        sendMessage($chatId, 'تم مسح بياناتك. يمكنك البدء من جديد عن طريق إدخال رصيد الإجازات.', $keyboard);
                        break;
                }

                // التعامل مع تعديل الرصيد
                if (is_numeric($text) && $usersData[$chatId]['state'] == 'updating_balance') {
                    // تعديل الرصيد
                    $usersData[$chatId]['balance'] = (int)$text;
                    $usersData[$chatId]['state'] = 'none';  // إعادة تعيين الحالة
                    saveData();
                    sendMessage($chatId, "تم تعديل رصيد إجازاتك إلى: $text يوم.", $keyboard);
                }

                // التعامل مع استقطاع الإجازة
                if (is_numeric($text) && $usersData[$chatId]['state'] == 'deducting_vacation') {
                    // استقطاع الإجازات
                    $daysToDeduct = (int)$text;
                    if ($daysToDeduct > 0 && $daysToDeduct <= $usersData[$chatId]['balance']) {
                        $usersData[$chatId]['balance'] -= $daysToDeduct;
                        $currentDate = date("Y-m-d"); // تاريخ اليوم الحالي
                        $endDate = date("Y-m-d", strtotime("+$daysToDeduct days")); // حساب تاريخ نهاية الإجازة
                        
                        // إضافة إلى سجل الإجازات
                        $usersData[$chatId]['vacationLog'][] = [
                            'days' => $daysToDeduct,
                            'start_date' => $currentDate,
                            'end_date' => $endDate
                        ];
                        $usersData[$chatId]['state'] = 'none'; // إعادة تعيين الحالة
                        saveData();

                        // إرسال الرسالة للمستخدم
                        if ($daysToDeduct == 1) {
                            // إذا كانت الإجازة ليوم واحد
                            sendMessage($chatId, "تم استقطاع إجازة ليوم واحد بتاريخ $currentDate.", $keyboard);
                        } else {
                            // إذا كانت الإجازة لأكثر من يوم
                            sendMessage($chatId, "تم استقطاع إجازة لمدة $daysToDeduct أيام من $currentDate إلى $endDate.", $keyboard);
                        }
                    } else {
                        sendMessage($chatId, 'لا يوجد رصيد كافي لاستقطاع هذا العدد من الأيام.', $keyboard);
                    }
                }
            }
        }
    }
}
?>