# bitrix-moysklad
Обратная синхронизация заказов из МойСклад в битрикс с созданием/изменением заказов в битриксе.
Для правильной работы создаем веб-хуки CREATE/UPDATE на сущности customerorder и retaildemand
в МойСклад с помощью create_hook.php, не забыв указать свой логин/пароль. 
Указать расположение create_order.php как обработчика веб-хуков.
