<?php

/**
 * Планировщик синхронизации: решает, какие узлы инвентори за какой узел
 * zabbix отвечают. Вынесен из sync.php отдельным классом, чтобы логику
 * можно было проверять тестами без конфига и сети.
 *
 * Две задачи:
 *  - сборка "стеков" оборудования (несколько techs с одной моделью и IP);
 *  - разрешение коллизий, когда несколько узлов инвентори резолвятся
 *    в один и тот же узел zabbix.
 *
 * Оборудование, которое конвейер снимает с мониторинга (status=1 — склад,
 * списание, архив), в стеки не собирается: его карточка тащит за собой
 * устаревшие model+ip, и мастером стека оно быть не должно.
 */
class syncPlanner {

	const STATUS_MONITORED=0;	//узел под мониторингом
	const STATUS_DISABLED=1;	//мониторинг узла приостановлен

	/**
	 * занятые узлы zabbix: hostid => true
	 * @var array
	 */
	protected $claims=[];

	/**
	 * Статус, который конвейер назначил узлу.
	 * После array_merge_recursive скаляры превращаются в массивы,
	 * поэтому разворачиваем так же, как applyPipelineActions.
	 * Отсутствие status = мониторинг не трогаем = узел считаем рабочим.
	 * @param array $params вывод конвейера по узлу
	 * @return int
	 */
	public static function entryStatus($params) {
		$status=$params['status']??self::STATUS_MONITORED;
		if (is_array($status)) $status=reset($status);
		return (int)$status;
	}

	/**
	 * Снимает ли эта запись узел с мониторинга
	 * @param array $params вывод конвейера по узлу
	 * @return bool
	 */
	public static function isDisabling($params) {
		return static::entryStatus($params)===self::STATUS_DISABLED;
	}

	/**
	 * Раскладывает результат работы конвейера в плоский список узлов
	 * к обработке, схлопывая стеки оборудования.
	 *
	 * Стек — это несколько единиц techs с одинаковыми моделью и IP,
	 * то есть физически собранные в стопку коммутаторы: мониторим только
	 * мастера (наименьший инвентарный номер), остальным гасим мониторинг.
	 *
	 * В группировку идёт только работающее оборудование: складская единица
	 * тащит за собой устаревшие model+ip из карточки, членом стека не является
	 * и мастером стать не должна - иначе пришедшее ей на замену устройство
	 * будет принудительно погашено вместе с ней.
	 *
	 * @param array $entries список ['item'=>узел инвентори,'params'=>вывод конвейера]
	 * @param callable|null $log куда писать сообщения о найденных стеках
	 * @return array тот же список, со схлопнутыми стеками
	 */
	public static function buildProcessedItems(array $entries,?callable $log=null) {
		$processedItems=[];
		$techStacks=[];

		foreach ($entries as $entry) {
			$item=$entry['item'];

			//не оборудование или не в работе - в стеки не собираем
			if (($item['class']??null)!=='techs' || static::isDisabling($entry['params'])) {
				$processedItems[]=$entry;
				continue;
			}

			$stackId=($item['model']['name']??'').'|'.($item['ip']??'');
			if (!isset($techStacks[$stackId])) $techStacks[$stackId]=[];
			$techStacks[$stackId][]=$entry;
		}

		foreach ($techStacks as $stack) {

			//не настоящий стек
			if (count($stack)===1) {
				$processedItems[]=$stack[0];
				continue;
			}

			//настоящий стек, сортируем по имени
			usort($stack,function($a,$b) {return strcmp($a['item']['num'],$b['item']['num']);});
			//мастер тот у кого имя меньше всех
			$master=array_shift($stack);

			if ($log) $log("Stack found: [{$master['item']['num']}], ".implode(', ',array_map(function($e) {return $e['item']['num'];},$stack))."\n");

			$processedItems[]=$master;
			foreach ($stack as $tech) {
				$tech['params']=['actions'=>['update'],'status'=>[self::STATUS_DISABLED]];	//можем только обновить статус на ВЫКЛ
				$processedItems[]=$tech;
			}
		}

		return $processedItems;
	}

	/**
	 * Закрепляет узел zabbix за обрабатываемой записью инвентори.
	 *
	 * Один узел zabbix обслуживает ровно одну запись инвентори: кто занял
	 * первым, тот и работает, остальные пропускаются. Передавать узел от
	 * одной записи к другой нельзя - за узлом стоит история наблюдений
	 * конкретного устройства, и склеивать в нём два разных устройства
	 * (тем более с разными шаблонами) неправильно. Замена оборудования -
	 * это новый узел zabbix, а не переприсвоение старого.
	 *
	 * @param string|int $hostid узел zabbix, в который отрезолвилась запись
	 * @return bool false - узел уже занят, запись надо пропустить
	 */
	public function claimHost($hostid) {
		if (isset($this->claims[$hostid])) return false;

		$this->claims[$hostid]=true;
		return true;
	}

}
