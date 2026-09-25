<?php

use TgJobParser\Web\Controller\ApiController;
use TgJobParser\Web\Controller\DashboardController;
use TgJobParser\Web\Controller\SettingsController;
use TgJobParser\Web\Controller\SourceController;
use TgJobParser\Web\Controller\VacancyController;

// [метод, путь, [контроллер, действие]] — новая страница = строка здесь + метод контроллера
return [
    ['GET', '/', [DashboardController::class, 'index']],

    ['POST', '/sources', [SourceController::class, 'add']],
    ['POST', '/sources/{id}/delete', [SourceController::class, 'delete']],
    ['POST', '/sources/{id}/toggle', [SourceController::class, 'toggle']],
    ['POST', '/parse', [SourceController::class, 'parse']],

    ['POST', '/vacancies/{id}/reject', [VacancyController::class, 'reject']],
    ['POST', '/vacancies/{id}/restore', [VacancyController::class, 'restore']],
    ['POST', '/vacancies/{id}/reset', [VacancyController::class, 'reset']],
    ['POST', '/vacancies/{id}/letter', [VacancyController::class, 'letter']],

    ['POST', '/settings/list/add', [SettingsController::class, 'addItem']],
    ['POST', '/settings/list/remove', [SettingsController::class, 'removeItem']],
    ['POST', '/settings/list/restore', [SettingsController::class, 'restoreItem']],
    ['POST', '/settings/general', [SettingsController::class, 'general']],
    ['POST', '/rescore', [SettingsController::class, 'rescore']],

    ['GET', '/api/vacancies', [ApiController::class, 'vacancies']],
    ['GET', '/api/health', [ApiController::class, 'health']],
];
