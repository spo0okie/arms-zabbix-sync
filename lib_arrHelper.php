#!/usr/bin/php
<?php

/*
 * Разные свитоперделки для работы с архивами
 */


class arrHelper {

	public static function getField($obj,$field,$default=null) {
		if (is_object($obj)) {
			if (property_exists($obj,$field))
				return $obj->$field;
		}
		if (is_array($obj)) {
			if (isset($obj[$field]))
				return $obj[$field];
		}
		return $default;
	}

	/**
	 * обновляет поле если оно есть
	 * @param $obj
	 * @param $field
	 * @param $value
	 */
	public static function updField(&$obj,$field,$value) {
		if (is_object($obj)) {
			if (property_exists($obj,$field)) {
				$obj->$field=$value;
				return;
			}
		}
		if (is_array($obj)) {
			if (isset($obj[$field])) {
				$obj[$field]=$value;
				return;
			}
		}
	}

	/**
	 * записывает поле
	 * @param $obj
	 * @param $field
	 * @param $value
	 */
	public static function setField(&$obj,$field,$value) {
		if (is_object($obj)) {
			$obj->$field=$value;
		}
		if (is_array($obj)) {
			$obj[$field]=$value;
		}
	}

	/**
	 * удаляет поле
	 * @param $obj
	 * @param $field
	 * @param $value
	 */
	public static function delField(&$obj,$field) {
		if (is_object($obj)) {
			unset($obj->$field);
		}
		if (is_array($obj)) {
			unset($obj[$field]);
		}
	}


	/* выбирает ключ, который указывает на массив подходящий под слово
	[
		"linux">	=>['Linux','Ubuntu','CentOS'],
		"server"	=>['Windows Server'],
		"wks"		=>['*']
	];*/
	public static function selectRegexKey($tplArray,$str) {
		foreach ($tplArray as $id=>$words) {
			if (!is_array($words)) $words=[$words];
			if (preg_match('/('.implode('|',$words).')/',$str))
				return $id;
        }
		return null;
	}

	/**
     * Проверяет что у $item все поля соответствуют фильтру [field1=>value1,field2=>value2]
	 * @param $item
	 * @param $filter
	 */
	public static function compareItemFields($item,$filter) {
		foreach ((array)$filter as $var => $value) {
		    $testValue=arrHelper::getField($item,$var);
		    if (is_array($testValue) || is_object($testValue)) {
		        if (!static::compareItemFields($testValue,$value)) return false;
            } else {
				if ($testValue != $value) {
					//echo "$var: $testValue != $value\n";
				    return false;
				}
            }
        }
		return true;
    }

	/**
	 * Проверка наличие элемента массива содержащего в себе набор $search[ключ1=>значение1,...]
	 * @param $array
	 * @param $search
	 * @return boolean|integer
	 */
	public static function getItemIdByFields($array,$search) {
		foreach ($array as $i=>$item) {
			if (static::compareItemFields($item,$search)) return $i;
		}
		return false;
	}

	/**
     * Поиск элемента массива содержащего в себе набор $search[ключ1=>значение1,...]
	 * @param $array
	 * @param $search
	 * @param null $default что вернуть если не найдено
	 */
	public static function getItemByFields($array,$search,$default=null) {

        foreach ($array as $item) {
	        if (static::compareItemFields($item,$search)) return $item;
        }

        return $default;
    }

	/**
	 * Поиск элемента массива содержащего в себе набор $search[ключ1=>значение1,...]
	 * @param $array
	 * @param $search
	 * @return array
	 */
	public static function getItemsByFields($array,$search) {
        $result=[];
		foreach ($array as $item) {
			if (static::compareItemFields($item,$search)) $result[]=$item;
		}
		return $result;
	}

	/**
	 * Замена элемента массива содержащего в себе набор $search[ключ1=>значение1,...]
	 * @param $array
	 * @param $search
	 */
	public static function updateItemByFields(&$array,$search,$newValue) {

		foreach ($array as $key=>$item) {
			$test = true;
			foreach ($search as $var => $value)
				$test = $test && arrHelper::getField($item,$var) == $value;

			if ($test) $array[$key]=$newValue;
		}
	}

	/**
	 * Удаление элемента массива содержащего в себе набор $search[ключ1=>значение1,...]
	 * @param $array
	 * @param $search
	 */
	public static function deleteItemByFields(&$array,$search) {

		foreach ($array as $key=>$item) {
			$test = true;
			foreach ($search as $var => $value)
				$test = $test && arrHelper::getField($item,$var) == $value;

			if ($test) unset($array[$key]);
		}
	}

	/*
     * Ищет Элемент по "значениям" дочерних элементов (хосты по значениям макросов)
	 * @param $array    array массив элементов среди которых ищем
	 * @param $search   array набор $search[ключ1=>значение1,...] для дочерних элементов
	 * @param $itemScheme array схема дочернего элемента [
	 * @return null
	public static function getItemBySubItems($array,$search,$itemScheme) {
	    $subItemName=$itemScheme['name'];
		$subItemVar=$itemScheme['var'];
		$subItemVal=$itemScheme['val'];
		//var_dump($search);
		foreach ($array as $item) {
			if (isset($item[$subItemName]) && is_array($item[$subItemName]) && count($item[$subItemName])) {
			    //echo "got $subItemName, iterating...\n";
				$test=true;
				//проверяем что внутри нашего набора макросов присутствуют все необходимые
				foreach ($search as $var=>$val)	$test=$test && !is_null(
						arrHelper::getItemByFields(
							$item[$subItemName],[
								$subItemVar=>$var,
								$subItemVal=>$val
							]
						)
					);
				if ($test) return $item;
			}
		}
		return null;
	}	 */


	/**
     * Возвращает значения полей элементов массива
	 * @param $array
	 * @param $field
	 * @return array
	 */
	public static function getItemsField($array,$field) {
	    $fields=[];
	    foreach ($array as $item) {
	        if (($value=static::getField($item,$field))!==null) $fields[]=$value;
        }
	    return $fields;
    }

    public static function getTreeValue($tree,$path,$default=null) {
	    //конвертируем путь 'parent/child/property' -> ['parent','child','property']
	    if (!is_array($path)) $path=explode('/',$path);
	    //вытаскиваем 'parent'
	    $key=array_shift($path);
	    if (!isset($tree[$key])) return $default; //если нет элемента - отвечаем как задумано

        return count($path)?    //весь путь прошли?
            self::getTreeValue($tree[$key],$path,$default): //если не весь, то идем дальше
            $tree[$key];    //отвечаем
    }

	/**
	 * возвращает элемент массива предварительно проверяя что он массив/конвертируя в массив
	 * @param $array
	 * @param $item
	 * @return array
	 */
	public static function getArrayArrayItem($array,$item) {
		if (!isset($array[$item])) return [];
		$value=$array[$item];
		if (!is_array($value)) $value=[$value];
		return $value;
	}

	/**
	 * возвращает элементы массива предварительно проверяя что они массив/конвертируя их в массив
	 * @param $array
	 * @return array
	 */
	public static function getArrayArrayItems($array) {
	    $arrItems=[];
	    foreach ($array as $key=>$value) {
			if (!is_array($value)) $value=[$value];
	        $arrItems[$key]=$value;
        }
		return $arrItems;
	}

	/**
     * Возвращает многостроковую переменную как набор строк за вычетом пустых строк
	 * @param $value
	 * @param false $keepEmptyStrings
	 * @return array
	 */
	public static function getMultiStringValue($value,$keepEmptyStrings=false) {
	    $result=[];
	    $items=explode("\n",$value);
	    if (count($items)==1) $items=explode("\r",$value);
	    foreach ($items as $string) {
	        if ($keepEmptyStrings || trim($value)) $result[]=$string;
        }
	    return $result;
    }

	/**
     * Ищет строку в наборе $search,
     * если в $search встречается элемент вида /.../ то он проверяется через preg_match
	 * @param $value
	 * @param $search
	 */
    public static function strMatch($value,$search) {
	    foreach ($search as $item) {
	        $len=strlen($item);
	        if ($len>2 && substr($item,0,1)==='/' && substr($item,$len-1,1)==='/') {
	            if (preg_match($item,$value)) return true;
            } else {
                if ($value==$item) return true;
            }
        }
	    return false;
    }

}
