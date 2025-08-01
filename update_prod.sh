#!/bin/bash
# Обновление кода Bitrix24 с GitHub на рабочем сервере

# Переход в каталог проекта (где лежит .git)
cd /home/bitrix/www || {
  echo "❌ Ошибка: каталог /home/bitrix/www не найден"
  exit 1
}

echo "🔄 Получаем обновления из GitHub..."
git fetch origin

echo "📌 Переключаемся на origin/main..."
git reset --hard origin/main

echo "✅ Обновление завершено. Текущий коммит:"
git log -1 --oneline
