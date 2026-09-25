<?php

declare(strict_types=1);

namespace TgJobParser\Profile;

use InvalidArgumentException;
use TgJobParser\Kernel\Config;
use TgJobParser\Repository\SettingsRepository;

/**
 * Собирает профиль: config/profile.php + правки из UI (settings).
 * Правки хранятся как дельта (добавлено/скрыто), поэтому обновление конфига
 * в репозитории не затирает пользовательские настройки и наоборот.
 */
final class ProfileProvider
{
    private ?CandidateProfile $profile = null;

    public function __construct(
        private readonly Config $config,
        private readonly SettingsRepository $settings,
    ) {
    }

    public function profile(): CandidateProfile
    {
        if ($this->profile !== null) {
            return $this->profile;
        }
        $base = $this->config->array('profile');
        $lists = (array) ($base['lists'] ?? []);
        foreach ($this->editableLists() as $name => $_label) {
            $lists[$name] = $this->effectiveList($name, (array) ($lists[$name] ?? []));
        }
        $flags = array_merge((array) ($base['flags'] ?? []), (array) $this->settings->get('profile.flags', []));
        $thresholds = array_merge((array) ($base['thresholds'] ?? []), (array) $this->settings->get('profile.thresholds', []));

        return $this->profile = new CandidateProfile(
            lists: $lists,
            flags: array_map('boolval', $flags),
            thresholds: array_map('intval', $thresholds),
            candidate: (array) ($base['candidate'] ?? []),
            experience: array_values((array) ($base['experience'] ?? [])),
            petProject: (array) ($base['pet_project'] ?? []),
        );
    }

    /** @return array<string, string> */
    public function editableLists(): array
    {
        return (array) $this->config->get('profile.editable_lists', []);
    }

    /**
     * Для UI: элементы списка с признаком происхождения.
     *
     * @return list<array{label: string, origin: string, hidden: bool}>
     */
    public function describeList(string $name): array
    {
        $this->assertEditable($name);
        $delta = $this->delta($name);
        $items = [];
        foreach ((array) $this->config->get("profile.lists.{$name}", []) as $definition) {
            $label = Term::from($definition)->label;
            $items[] = ['label' => $label, 'origin' => 'config', 'hidden' => in_array($label, $delta['removed'], true)];
        }
        foreach ($delta['added'] as $label) {
            $items[] = ['label' => $label, 'origin' => 'user', 'hidden' => false];
        }

        return $items;
    }

    public function addToList(string $name, string $value): void
    {
        $this->assertEditable($name);
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 300) {
            throw new InvalidArgumentException('Значение пустое или слишком длинное');
        }
        if ($name !== 'letter_requirements' && !Matcher::isValidPattern($value)) {
            throw new InvalidArgumentException('Некорректный шаблон: ' . $value);
        }
        $delta = $this->delta($name);
        $delta['removed'] = array_values(array_diff($delta['removed'], [$value]));
        $configLabels = TermList::from((array) $this->config->get("profile.lists.{$name}", []))->labels();
        if (!in_array($value, $configLabels, true) && !in_array($value, $delta['added'], true)) {
            $delta['added'][] = $value;
        }
        $this->saveDelta($name, $delta);
    }

    public function removeFromList(string $name, string $value): void
    {
        $this->assertEditable($name);
        $delta = $this->delta($name);
        if (in_array($value, $delta['added'], true)) {
            $delta['added'] = array_values(array_diff($delta['added'], [$value]));
        } elseif (!in_array($value, $delta['removed'], true)) {
            $delta['removed'][] = $value;
        }
        $this->saveDelta($name, $delta);
    }

    public function restoreInList(string $name, string $value): void
    {
        $this->assertEditable($name);
        $delta = $this->delta($name);
        $delta['removed'] = array_values(array_diff($delta['removed'], [$value]));
        $this->saveDelta($name, $delta);
    }

    /** @param array<string, bool> $flags */
    public function setFlags(array $flags): void
    {
        $known = array_keys((array) $this->config->get('profile.flags', []));
        $current = (array) $this->settings->get('profile.flags', []);
        foreach ($flags as $name => $value) {
            if (in_array($name, $known, true)) {
                $current[$name] = (bool) $value;
            }
        }
        $this->settings->set('profile.flags', $current);
        $this->profile = null;
    }

    /** @param array<string, int> $thresholds */
    public function setThresholds(array $thresholds): void
    {
        $min = max(0, min(100, (int) ($thresholds['min_score'] ?? $this->profile()->threshold('min_score', 40))));
        $rec = max($min, min(100, (int) ($thresholds['recommended'] ?? $this->profile()->threshold('recommended', 70))));
        $this->settings->set('profile.thresholds', ['min_score' => $min, 'recommended' => $rec]);
        $this->profile = null;
    }

    /**
     * @param list<mixed> $base
     * @return list<mixed>
     */
    private function effectiveList(string $name, array $base): array
    {
        $delta = $this->delta($name);
        $result = [];
        foreach ($base as $definition) {
            if (!in_array(Term::from($definition)->label, $delta['removed'], true)) {
                $result[] = $definition;
            }
        }
        foreach ($delta['added'] as $label) {
            $result[] = $label;
        }

        return $result;
    }

    /** @return array{added: list<string>, removed: list<string>} */
    private function delta(string $name): array
    {
        $delta = (array) $this->settings->get("profile.list.{$name}", []);

        return [
            'added' => array_values(array_map('strval', (array) ($delta['added'] ?? []))),
            'removed' => array_values(array_map('strval', (array) ($delta['removed'] ?? []))),
        ];
    }

    /** @param array{added: list<string>, removed: list<string>} $delta */
    private function saveDelta(string $name, array $delta): void
    {
        $this->settings->set("profile.list.{$name}", $delta);
        $this->profile = null;
    }

    private function assertEditable(string $name): void
    {
        if (!array_key_exists($name, $this->editableLists())) {
            throw new InvalidArgumentException("Список {$name} нельзя редактировать из интерфейса");
        }
    }
}
