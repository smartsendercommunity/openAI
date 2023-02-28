# openAI
Интеграция с openAI (создатели chatGPT)


Инструкция:
1. Загрузить файл на Ваш хостинг
2. Указать в файле токен Smart Sender и токен openAI (получить тут: https://platform.openai.com/account/api-keys )
3. В нужном месте воронки использовать блок "Действие - Внешний запрос" с типом POST и следующим телом запроса:
```
{
    "userId":"89422440",
    "request":"%text% Что делаеш?",
    "response":"Ответ за %time%.\n%result%"
}
```
где:
request - вопрос к openAI. При наличии в вопросе %text%, вместо него подставляется последнее сообщение пользователя
response - ответ пользователю. Обезательно должен содержать %result% вместо которого подставляется ответ от openAI. Вместо %time% подставляется время обработки в формате "15сек" (необезательно)

Так как ответ от openAI может поступать с задержкой, скрипт сам отправляет сообщение с ответом пользователю и соответствия для сохранения ответа не требуются
