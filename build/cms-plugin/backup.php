<?php
/**
 * ----------------------------------------------------------------------------
 * $Id: Make sql-backup and restore from backup for mysql databases, sergekoriakin@gmail.com,
 * ver: 1.1, Last build: 20120214 1308
 * GIT: https://github.com/Ksnk/Backup-script$
 * ----------------------------------------------------------------------------
 * License GNU/LGPL - Serge Koriakin - Jule 2010-2012
 * ----------------------------------------------------------------------------
 */

/**
 * Exception для определенности - будет тикать в случае ошибки
 */
class BackupException extends Exception { }

/**
 * собственно класс бякапа
 * - умеет читать писать GZ
 * - умеет читать большие и страшные дампы SypexDumper'а и большие и страшные дампы phpMyAdmin'а
 * - При создании дампа проверяет время изменения таблиц и в случае изменения - переделывает все заново.
 *   Так что можно не лочить базу - что есть неимоверная польза.
 */
class BACKUP {
    /**
     * Параметры класса - можно и нужно допилить по месту напильником
     */
    private $opt=array(
// настройка на базу
        'host'=>'localhost', // хост
        'user'=>'root', // имя-пароль
        'password'=>'',
        'base'=>'tmp',  // имя базы данных
//  backup-only параметры
        'include'=>'*', // маска в DOS стиле со * и ? . backup-only
        'exclude'=>'',  // маска в DOS стиле со * и ? . backup-only
        'compress'=>9, // уровень компрессии для gz  . backup-only
        'method'=>'sql.gz', // 'sql.gz'|'sql.bz2'|'sql' - использовать gz или нет
        'onthefly'=>false , // вывод гзипа прямо в броузер. Ошибки, правда, теряются напрочь...
//  both-way параметры
        'file'=>'',  // имя файла SQL-дампа для чтения или каталог (с / на конце) для создания базы
        'code'=>'utf8', // set NAMES 'code'
        'progress'=>'', // функция для calback'а progress bar'a
        'progressdelay'=>1, // время между тиками callback прогресс бара [0.5== полсекунды]
//  restore-only параметры
        'sql'=>'', // plain sql to execute with backup class.
    );

    /**
     * @var int - ограничение на длину одного запроса (нежесткое, как получится, при первой возможности :))
     * Еще и размер буфера для чтения sql файла
     */
    static private $MAXBUF=32768    ;
    /**
     * @var int - ограничение на количество попыток сделать бякап
     */
    static private $MAX_REPEAT_BACKUP = 5 ;

    /** on-the-fly support*/
    private $fsize,$fltr,$hctx ;

    /** make backup support*/
    private
        /** @var array - hold tables name as result of INCLUDE-EXCLUDE calculations */
        $tables=array()
        /** @var array - hold tables modfication time */
        ,$times=array();

    /** @var bool|\resource */
    private $link = false;

    /** @var string - sql|sql.gz - метод работы с файлами */
    private $method = 'file';

    /**
     * Внутренняя отладочная функция. Имеет содержимое только для специального варианта
     * сборки или для тестовых прогонов
     * @param $message
     */
    private function log($message){
    }

    /**
     * внутренняя функция вывод прогресса операции.
     * @param $name
     * @param bool $call
     * @return mixed
     */
    private function progress($name,$call=false){
        static $starttime,$param=array();
        if(!is_callable($this->opt['progress'])) return;
        if(is_array($name))
            $param=array_merge($param,$name);
        else
            $param['val']=$name;

        if (!isset($starttime) || $call ||(microtime(true)-$starttime)>$this->opt['progressdelay']){
            call_user_func($this->opt['progress'],&$param);
            $starttime=microtime(true);
        }
    }

    /**
     * построить имя файла с помощью каталога из параметра opt['files']
     * @param $name
     * @return string
     */
    public function directory($name=''){
        if(empty($this->opt['file']))
            $file='';
        else if(is_dir($this->opt['file']))
            $file=rtrim($this->opt['file'],' \/');
        else
            $file=trim(dirname($this->opt['file']));
        if(!empty($file))
            $file.='/';
        return $file.$name;
    }

    /**
     * @param string $options
     * @param string $val
     * @return array
     */
    public function options($options='',$val=''){
        if(is_array($options))
            $this->opt=array_merge($this->opt,array_intersect_key($options,$this->opt));
        else
            $this->opt[$options]=$val;
    }
    /**
     * просто конструктор
     * @param array $options - те параметры, которые отличаются от дефолтных
     */
    public function __construct($options=array()){
        /* вот так устанавливаются параметры */
        $this->options(&$options);
        // so let's go
        $this->link = mysql_connect($this->opt['host'], $this->opt['user'], $this->opt['pass']);
        $this->opt['base']=mysql_real_escape_string($this->opt['base']);
        if(!mysql_select_db($this->opt['base'], $this->link)){
            throw new BackupException('Can\'t use `'.$this->opt['base'].'` : ' . mysql_error());
        };
        mysql_query('set NAMES "'.mysql_real_escape_string($this->opt['code']).'";');
    }

    /**
     * просто деструктор
     */
    function __destruct(){
        mysql_close($this->link);
    }

    /**
     * добиваемся прозрачности GZ и обычного чтения файла
     * Заменитель стандартных open-close. read и write остаются fread и fwrite. Just a magic!
     * @param $name - имя файла.
     * @param string $mode - режим открытия файла (w|r)
     * @return resource - вертает результат соответствующей операции
     */
    function open($name,$mode='r'){
        if(preg_match('/\.(sql|sql\.bz2|sql\.gz)$/i', $name, $m))
            $this->method = strtolower($m[1]);
        if($mode == 'w' && $this->method=='sql') { // forcibly change type to gzip
            $this->method=$this->opt['method'];
            if(!$this->opt['onthefly']){
                if ($this->method=='sql.gz')
                    $name.='.gz';
                else if($this->method=='sql.bz2')
                    $name.='.bz2';
            }
        }
        $this->fsize = 0;

        if($this->opt['sql'] && $mode=='r'){
            $handle=@fopen("php://temp", "w+b");
            if ($handle === FALSE)
                throw new BackupException('It\' impossible to use `php://temp`, sorry');
            fwrite($handle,preg_replace(
                '~;\s*(insert|create|delete|drop)~i',";\n\\1",
                $this->opt['sql']
            ));
            fseek($handle,0);
            return $handle;
        }
        else if($this->opt['onthefly'] && $mode=='w'){ // gzzip on-the-fly without file
            $this->opt['progress']=''; // switch off progress  :(
            $handle=@fopen("php://output", "wb");
            if ($handle === FALSE)
                throw new BackupException('It\' impossible to use `gzip-on-the-fly`, sorry');
            header($_SERVER["SERVER_PROTOCOL"] . ' 200 OK');
            header('Content-Type: application/octet-stream');
            header('Connection: keep-alive'); // so it's possible to skip filesize header
            header('Content-Disposition: attachment; filename="' . basename($name.'.gz') . '";');
            // write gzip header
            fwrite($handle, "\x1F\x8B\x08\x08".pack("V", time())."\0\xFF", 10);
            // write the original file name
            $oname = str_replace("\0", "", $name);//TODO: wtf?
            fwrite($handle, $oname."\0", 1+strlen($oname));
            // add the deflate filter using default compression level
            $this->fltr = stream_filter_append($handle, "zlib.deflate", STREAM_FILTER_WRITE, -1);
            $this->hctx = hash_init("crc32b");// set up the CRC32 hashing context
            // turn off the time limit
            if (!ini_get("safe_mode")) set_time_limit(0);
            return $handle;
        }
        else {
            if($mode=='r' && !is_readable($name)) return FALSE;
            if($this->method=='sql.bz2'){
                if(function_exists('bzopen'))
                    return bzopen($name, $mode);
                else {
                    $this->method='sql.gz';
                    $name=preg_replace('/\.bz2$/i','.gz', $name);
                }
            }
            if($this->method=='sql.gz'){
                return gzopen($name,$mode.($mode == 'w' ? $this->opt['compress'] : ''));
            } else {
                return fopen($name,"{$mode}b");
            }
        }
    }

    /**
     * заменитель write - поддержка счетчика записанных байтов.
     * @param $handle
     * @param $str
     * @return int
     */
    function write($handle,$str){
        if(!empty($this->fltr)){
            hash_update($this->hctx, $str);
            $this->fsize+=strlen($str);
        }
        return fwrite($handle,&$str);
    }
    /**
     * заменитель close
     * @param resource $handle
     */
    function close($handle){
        if(!empty($this->fltr)){
            stream_filter_remove($this->fltr);$this->fltr=null;
            // write the original crc and uncompressed file size
            $crc = hash_final($this->hctx, TRUE);
                // need to reverse the hash_final string so it's little endian
            fwrite($handle, $crc[3].$crc[2].$crc[1].$crc[0], 4);
            //fwrite($handle, pack("V", hash_final($this->hctx, TRUE)), 4);
            fwrite($handle, pack("V",$this->fsize), 4);
        }
        // just a magic! No matter a protocol
        fclose($handle);
    }

    /**
     * Читаем дамп и выполняем все Sql найденные в нем.
     * @return bool
     */
    public function restore(){
        $this->log(sprintf('before restore "%s" ',$this->opt['file']));
        $handle=$this->open($this->opt['file']);
        if(!is_resource($handle))
            throw new BackupException('File not found "'.$this->opt['file'].'"');
        $notlast=true;
        $buf='';
        @ignore_user_abort(1); // ибо нефиг
        @set_time_limit(0); // ибо нефиг, again
        //Seek to the end
        /** @var $line - line count to point to error line */
        $line=0;
        if($this->opt['method']=='sql.gz'){
            // find a sizesize
            @gzseek($handle, 0, SEEK_END);
            $total = gztell($handle);
            gzseek($handle, 0, SEEK_SET);
        } else {
            fseek($handle, 0, SEEK_END);
            $total = ftell($handle);
            fseek($handle, 0, SEEK_SET);
        }
        $curptr=0;
        $this->progress(array('name'=>'restore','val'=>0,'total'=>$total));
        do{
            $string=fread($handle,self::$MAXBUF);
            $xx=explode(";\n",str_replace("\r","",$buf.$string));

            if(strlen($string)!=self::$MAXBUF){
                $notlast=false;
            } else {
                $buf=array_pop($xx);
            }
            $this->progress($curptr+=strlen($string));

            foreach($xx as $s){
                $clines=0;
                str_replace("\n","\n",$s,$clines);$line+=$clines+1; // point to last string in executing query.
                // устраняем строковые комментарии
                $s=trim(preg_replace('~^\-\-.*?$|^#.*?$~m','',$s));
                if(!empty($s)) {
                    //echo ' x'.strlen($s).' ';
                    $result=mysql_query($s);
                    if(!$result){
                        // let' point to first line
                        str_replace("\n","\n",$s,$clines);
                        throw new BackupException(sprintf(
                            "Invalid query at line %s: %s\nWhole query: %s"
                        , $line-$clines, mysql_error(),str_pad($s,200)));
                    }
                    if(is_resource($result))
                        mysql_free_result($result);
                }
            };

            unset($string,$xx); // очищаем наиболее одиозные хапалки памяти
        }
        while($notlast);
        unset($buf);// очищаем наиболее одиозные хапалки памяти

        $this->close($handle);
        $this->progress('Ok',true);
        $this->log(sprintf('after restore "%s" ',$this->opt['file']));

        return true;
    }

    /**
     * вызов функции проверки времени модификации таблиц
     * @return bool
     */
    function tableChanged(){
        // не поменялись ли таблицы за время дискотеки?
        $changed=false;
        $result = mysql_query('SHOW TABLE STATUS FROM `'.$this->opt['base'].'` like "%"');
        while ($row = mysql_fetch_assoc($result))
        {
            if(in_array($row['Name'],$this->tables)) {
                if($this->times[$row['Name']] != $row['Update_time']){
                    $this->times[$row['Name']] = $row['Update_time'];
                    $changed=false;
                }
            }
            unset($row);
        }
        mysql_free_result($result);
        return $changed;
    }

    /**
     * изготавливаем бякап
     * @return bool
     */
    public function make_backup()
    {
        $include=array();$exclude=array();
        $this->log(sprintf('before makebackup "%s" ',$this->opt['file']));
        // делаем регулярки из простой маски
        foreach(array('include','exclude') as $s){
            $$s=explode(',',$this->opt[$s]);
            foreach($$s as &$x){
                $x='~^'.str_replace(array('~','*','?'),array('\~','.*','.'),$x).'$~';
            }
            unset($x);
        }

        $total = array(); // время последнего изменения
        $result = mysql_query('SHOW TABLE STATUS FROM `'.$this->opt['base'].'` like "%"');
        if(!$result){
            throw new BackupException('Invalid query: ' . mysql_error() . "\n");
        }
        // запоминаем время модификации таблиц и таблицы, подходящие нам по маске
        while ($row = mysql_fetch_assoc($result))
        {
            foreach($include as $i){
                if(preg_match($i,$row['Name'])){
                    foreach($exclude as $x)
                        if(preg_match($x,$row['Name'])){
                            break 2;
                        }
                    $this->tables[] = $row['Name'];
                    $this->times[$row['Name']] = $row['Update_time'];
                    $total[$row['Name']] = $row['Rows'];
                    break;
                }
            }
            unset($row);
        }
        unset($include,$exclude);
        //var_dump($this->tables);
        mysql_free_result($result);

        $this->log(sprintf('1step makebackup "%s" ',$this->opt['file']));
        @ignore_user_abort(1); // ибо нефиг
        @set_time_limit(0); // ибо нефиг, again

        $repeat_cnt = self::$MAX_REPEAT_BACKUP;

        do {
            if(trim(basename($this->opt['file']))=='') {
                $this->opt['file']=$this->directory(sprintf('db-%s-%s.sql',$this->opt['base'],date('Ymd')));
            }
            $handle = $this->open($this->opt['file'],'w');
            if(!$handle)
                throw new BackupException('Can\'t create file "'.$this->opt['file'].'"');
            $this->write($handle, sprintf("--\n"
                .'-- "%s" database with +"%s"-"%s" tables'."\n"
                .'--     '.implode("\n--     ",$this->tables)."\n"
                .'-- backup created: %s'."\n"
                ."--\n\n"
                ,$this->opt['base'],$this->opt['include'],$this->opt['exclude'],date('j M y H:i:s')));
            $retrow=array();
            $str_len=0;
            //Проходим в цикле по всем таблицам и форматируем данные
            foreach ($this->tables as $table)
            {

                if(isset($notNum)) unset ($notNum);
                $notNum = array();
                $this->log(sprintf('3step makebackup "%s" ',$table));
                // нагло потырено у Simpex Dumper'а
                $r = mysql_query("SHOW COLUMNS FROM `$table`");
                $num_fields = 0;
                while($col = mysql_fetch_array($r)) {
                    $notNum[$num_fields++] = preg_match("/^(tinyint|smallint|mediumint|bigint|int|float|double|real|decimal|numeric|year)/", $col['Type']) ? 0 : 1;
                }
                mysql_free_result($r);
                $this->write($handle,'DROP TABLE IF EXISTS `' . $table . '`;');
                $r=mysql_query('SHOW CREATE TABLE ' . $table);
                $row2 = mysql_fetch_row($r);
                if(is_resource($r)) mysql_free_result($r);

                $this->write($handle,"\n\n" . $row2[1] . ";\n\n");

                $result = mysql_unbuffered_query('SELECT * FROM `' . $table.'`',$this->link);
                $rowcnt=0;
                $this->progress(array('name'=>$table,'val'=>0,'total'=>$total[$table]));

                $sql_insert_into="INSERT INTO `" . $table . "` VALUES\n  ";
                $sql_glue=",\n  ";

                while ($row = mysql_fetch_row($result))
                {
                    $rowcnt++;
                    $this->progress($rowcnt);

                    for ($j = 0; $j < $num_fields; $j++)
                    {
                        if (is_null($row[$j]))
                            $row[$j] =  'NULL' ;
                        elseif($notNum[$j]){
                            $row[$j] =  '\'' . str_replace('\\"','"',mysql_real_escape_string($row[$j])) . '\'';
                        }
                    }
                    $str='('.implode(', ',$row).')';
                    $str_len+=strlen($str)+1; //+str_len($sql_glue);// вместо 1 не надо, иначе на phpMySqlAdmin не будет похоже
                    // Смысл - хочется выполнять не очень здоровые SQL запросы, если есть возможность.
                    if($str_len>self::$MAXBUF-strlen($sql_insert_into)){
                        $this->write($handle,$sql_insert_into.implode($sql_glue,$retrow).";\n\n");
                        unset($retrow);
                        $retrow=array();
                        $str_len=strlen($str);
                    }
                    $retrow[]=$str;
                    unset($row,$str);
                }
                $this->progress('Ok',true);

                if(count($retrow)>0){
                    $this->write($handle,$sql_insert_into.implode($sql_glue,$retrow).";\n\n");
                    unset($retrow);
                    $retrow=array();
                    $str_len=0;
                }
                mysql_free_result($result);
                $this->write($handle,"\n");
            }
            //сохраняем файл
            $this->close($handle);

        } while( $this->tableChanged() && ($repeat_cnt--)>0);

        if($repeat_cnt<=0){
            throw new BackupException('Can\'t create backup. Heavy traffic, sorry. Try another day?' . "\n");
        }

        $this->log(sprintf('after makebackup "%s" ',$this->opt['file']));
        return true;
    }
}