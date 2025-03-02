<?php

// Функция для создания папки и сохранения данных
function saveCarData(string $carplate, array $data): void {
    // Создаем папку, если она не существует
    if (!file_exists($carplate)) {
        mkdir($carplate, 0777, true);
    }

    // Сохраняем изображение, если оно есть
    if (!empty($data['image'])) {
        $imageData = file_get_contents($data['image']);
        if ($imageData !== false) {
            file_put_contents("$carplate/preview.jpg", $imageData);
        }
    }

    // Удаляем изображение из данных, чтобы не сохранять его в JSON
    unset($data['image']);

    // Сохраняем данные в JSON
    file_put_contents("$carplate/info.json", json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// Функция для выполнения HTTP-запроса
function makeRequest(string $url, array $postData): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData, '', '&'));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HEADER, false);
    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true) ?? [];
}

// Функция для получения данных об автомобиле
function getCarData(string $carplate): array {
    // Получаем токен
    $tokenResponse = makeRequest(
        'https://auto.drom.ru/ajax/?mode=check_autohistory_report_captha&crossdomain_ajax_request=3',
        ['carplate' => $carplate]
    );

    if (empty($tokenResponse['token'])) {
        return [];
    }

    // Получаем данные об автомобиле
    $carDataResponse = makeRequest(
        'https://auto.drom.ru/ajax/?mode=check_autohistory_gibdd_info&crossdomain_ajax_request=3',
        ['token' => $tokenResponse['token']]
    );

    return $carDataResponse['carData'] ?? [];
}

// Основной код
if ($argc < 2) {
    die("Использование: php index.php <input_file>\n");
}

$inputFile = $argv[1];
if (!file_exists($inputFile)) {
    die("Файл не найден\n");
}

$file = fopen($inputFile, 'r');
if (!$file) {
    die("Не удалось открыть файл\n");
}

$batchSize = 24; // Количество одновременных запросов

while (!feof($file)) {
    $carplates = [];
    for ($i = 0; $i < $batchSize; $i++) {
        $line = fgets($file);
        if ($line === false) {
            break;
        }
        $carplates[] = trim($line);
    }

    if (empty($carplates)) {
        break;
    }

    $mh = curl_multi_init();
    $handles = [];

    // Инициализация многопоточных запросов для получения токенов
    foreach ($carplates as $carplate) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://auto.drom.ru/ajax/?mode=check_autohistory_report_captha&crossdomain_ajax_request=3');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['carplate' => $carplate], '', '&'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_multi_add_handle($mh, $ch);
        $handles[$carplate] = $ch;
    }

    // Выполнение многопоточных запросов
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh);
    } while ($running > 0);

    sleep(1); // Задержка для регистрации токенов

    // Обработка результатов
    foreach ($handles as $carplate => $ch) {
        $response = curl_multi_getcontent($ch);
        $json_array = json_decode($response, true);
        $token = $json_array['token'] ?? null;

        if (!$token) {
            echo "Ошибка: Не удалось получить токен для номера $carplate\n";
            continue;
        }

        // Получаем данные об автомобиле
        $carDataResponse = makeRequest(
            'https://auto.drom.ru/ajax/?mode=check_autohistory_gibdd_info&crossdomain_ajax_request=3',
            ['token' => $token]
        );

        if (empty($carDataResponse['carData'])) {
            echo "Ошибка: Нет данных об автомобиле для номера $carplate\n";
            continue;
        }

        // Формируем данные для сохранения
        $carData = [
            'vin' => $carDataResponse['carData']['vin'] ?? '',
            'volume' => $carDataResponse['carData']['volume'] ?? 0,
            'power' => $carDataResponse['carData']['power'] ?? 0,
            'carplate' => mb_strtoupper($carDataResponse['carData']['carplate'] ?? $carplate),
            'color' => $carDataResponse['carData']['color'] ?? '',
            'type' => $carDataResponse['carData']['type'] ?? '',
            'image' => $carDataResponse['carData']['image'] ?? '',
        ];

        // Сохраняем данные
        saveCarData($carplate, $carData);
        curl_multi_remove_handle($mh, $ch);
    }

    curl_multi_close($mh);
}

fclose($file);
