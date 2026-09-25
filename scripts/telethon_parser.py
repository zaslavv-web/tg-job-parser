#!/usr/bin/env python3
"""
Telethon-адаптер для приватных каналов (ТЗ 4.1, вариант B).

Контракт с PHP (TelethonDriver): JSON в stdout.
  успех:  {"title": "...", "posts": [{"id": 123, "text": "...", "date": "ISO",
            "url": "https://t.me/c/..", "links": [...], "buttons": [{"label": "..", "url": ".."}]}]}
  ошибка: {"error": "..."} и ненулевой код выхода.

Первичная авторизация (один раз, интерактивно):
  TG_API_ID=... TG_API_HASH=... python3 scripts/telethon_parser.py --login
Зависимость: pip install telethon
"""
import argparse
import asyncio
import json
import os
import sys


def fail(message: str, code: int = 1) -> None:
    print(json.dumps({"error": message}, ensure_ascii=False))
    sys.exit(code)


try:
    from telethon import TelegramClient
    from telethon.tl.types import MessageEntityTextUrl, MessageEntityUrl, KeyboardButtonUrl
except ImportError:
    fail("Не установлен telethon: pip install telethon")


def extract_links(message) -> list:
    links = []
    text = message.message or ""
    for entity in message.entities or []:
        if isinstance(entity, MessageEntityTextUrl):
            links.append(entity.url)
        elif isinstance(entity, MessageEntityUrl):
            links.append(text[entity.offset: entity.offset + entity.length])
    return list(dict.fromkeys(links))


def extract_buttons(message) -> list:
    buttons = []
    markup = getattr(message, "reply_markup", None)
    for row in getattr(markup, "rows", []) or []:
        for button in row.buttons:
            if isinstance(button, KeyboardButtonUrl):
                buttons.append({"label": button.text, "url": button.url})
    return buttons


async def fetch(args) -> dict:
    client = TelegramClient(args.session, int(args.api_id), args.api_hash)
    await client.connect()
    if not await client.is_user_authorized():
        await client.disconnect()
        fail("Сессия Telethon не авторизована: запустите скрипт с --login")

    channel = args.channel
    if channel.lstrip("-").isdigit():
        channel = int(channel)
    try:
        entity = await client.get_entity(channel)
    except Exception as exc:  # noqa: BLE001 — любую ошибку отдаём в PHP как JSON
        await client.disconnect()
        fail(f"Канал недоступен: {exc}")

    username = getattr(entity, "username", None)
    posts = []
    async for message in client.iter_messages(entity, limit=args.limit, min_id=args.min_id or 0):
        text = message.message or ""
        if not text.strip():
            continue
        url = f"https://t.me/{username}/{message.id}" if username else f"https://t.me/c/{entity.id}/{message.id}"
        posts.append({
            "id": message.id,
            "text": text,
            "date": message.date.isoformat() if message.date else None,
            "url": url,
            "links": extract_links(message),
            "buttons": extract_buttons(message),
        })
    await client.disconnect()
    return {"title": getattr(entity, "title", None), "posts": posts}


async def login(args) -> None:
    client = TelegramClient(args.session, int(args.api_id), args.api_hash)
    await client.start()
    me = await client.get_me()
    await client.disconnect()
    print(json.dumps({"ok": True, "user": getattr(me, "username", None)}, ensure_ascii=False))


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--channel")
    parser.add_argument("--limit", type=int, default=100)
    parser.add_argument("--min-id", type=int, default=0)
    parser.add_argument("--login", action="store_true")
    parser.add_argument("--api-id", default=os.environ.get("TG_API_ID"))
    parser.add_argument("--api-hash", default=os.environ.get("TG_API_HASH"))
    parser.add_argument("--session", default=os.environ.get("TG_SESSION", "var/telethon/session"))
    args = parser.parse_args()

    if not args.api_id or not args.api_hash:
        fail("Не заданы TG_API_ID / TG_API_HASH")
    os.makedirs(os.path.dirname(os.path.abspath(args.session)), exist_ok=True)

    if args.login:
        asyncio.run(login(args))
        return
    if not args.channel:
        fail("Не указан --channel")
    print(json.dumps(asyncio.run(fetch(args)), ensure_ascii=False))


if __name__ == "__main__":
    main()
