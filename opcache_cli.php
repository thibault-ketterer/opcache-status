<?php

define('THOUSAND_SEPARATOR',true);

if (!extension_loaded('Zend OPcache')) {
    echo '<div style="background-color: #F2DEDE; color: #B94A48; padding: 1em;">You do not have the Zend OPcache extension loaded, sample data is being shown instead.</div>';
    require 'data-sample.php';
}

class OpCacheDataModel
{
    private $_configuration;
    private $_status;
    private $_d3Scripts = array();

    public function __construct()
    {
        $this->_configuration = opcache_get_configuration();
        $this->_status = opcache_get_status();
    }

    public function getPageTitle()
    {
        return 'PHP ' . phpversion() . " with OpCache {$this->_configuration['version']['version']}";
    }

    public function getStatusDataRows()
    {
        $rows = array();
        foreach ($this->_status as $key => $value) {
            if ($key === 'scripts') {
                continue;
            }

            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    if ($v === false) {
                        $value = 'false';
                    }
                    if ($v === true) {
                        $value = 'true';
                    }
                    if ($k === 'used_memory' || $k === 'free_memory' || $k === 'wasted_memory') {
                        $v = $this->_size_for_humans(
                            $v
                        );
                    }
                    if ($k === 'current_wasted_percentage' || $k === 'opcache_hit_rate') {
                        $v = number_format(
                                $v,
                                2
                            ) . '%';
                    }
                    if ($k === 'blacklist_miss_ratio') {
                        $v = number_format($v, 2) . '%';
                    }
                    if ($k === 'start_time' || $k === 'last_restart_time') {
                        $v = ($v ? date(DATE_RFC822, $v) : 'never');
                    }
                    if (THOUSAND_SEPARATOR === true && is_int($v)) {
                        $v = number_format($v);
                    }

                    $rows[] = "$k	$v\n";
                }
                continue;
            }
            if ($value === false) {
                $value = 'false';
            }
            if ($value === true) {
                $value = 'true';
            }
            $rows[] = "$key	$value\n";
        }

        return implode("\n", $rows);
    }

    public function getConfigDataRows()
    {
        $rows = array();
        foreach ($this->_configuration['directives'] as $key => $value) {
            if ($value === false) {
                $value = 'false';
            }
            if ($value === true) {
                $value = 'true';
            }
            if ($key == 'opcache.memory_consumption') {
                $value = $this->_size_for_humans($value);
            }
            $rows[] = "$key	$value\n";
        }

        return implode("\n", $rows);
    }

    public function getScriptStatusRows()
    {
        foreach ($this->_status['scripts'] as $key => $data) {
            $dirs[dirname($key)][basename($key)] = $data;
            $this->_arrayPset($this->_d3Scripts, $key, array(
                'name' => basename($key),
                'size' => $data['memory_consumption'],
            ));
        }

        asort($dirs);

        $basename = '';
        while (true) {
            if (count($this->_d3Scripts) !=1) break;
            $basename .= DIRECTORY_SEPARATOR . key($this->_d3Scripts);
            $this->_d3Scripts = reset($this->_d3Scripts);
        }

        $this->_d3Scripts = $this->_processPartition($this->_d3Scripts, $basename);
        $id = 1;

        $rows = array();
        foreach ($dirs as $dir => $files) {
            $count = count($files);
            $file_plural = $count > 1 ? 's' : null;
            $m = 0;
            foreach ($files as $file => $data) {
                $m += $data["memory_consumption"];
            }
            $m = $this->_size_for_humans($m);

            if ($count > 1) {
                $rows[] = '<tr>';
                $rows[] = "<th class=\"clickable\" id=\"head-{$id}\" colspan=\"3\" onclick=\"toggleVisible('#head-{$id}', '#row-{$id}')\">{$dir} ({$count} file{$file_plural}, {$m})</th>";
                $rows[] = '</tr>';
            }

            foreach ($files as $file => $data) {
                $rows[] = "{$id}".
                          "	" . $this->_format_value($data["hits"]).
                          "	" . $this->_size_for_humans($data["memory_consumption"]).
                          $count > 1 ? " {$file}" : "{$dir}/{$file}";
                          '\n';
            }

            ++$id;
        }

        return implode("\n", $rows);
    }

    public function getScriptStatusCount()
    {
        return count($this->_status["scripts"]);
    }

    public function getGraphDataSetJson()
    {
        $dataset = array();
        $dataset['memory'] = array(
            $this->_status['memory_usage']['used_memory'],
            $this->_status['memory_usage']['free_memory'],
            $this->_status['memory_usage']['wasted_memory'],
        );

        $dataset['keys'] = array(
            $this->_status['opcache_statistics']['num_cached_keys'],
            $this->_status['opcache_statistics']['max_cached_keys'] - $this->_status['opcache_statistics']['num_cached_keys'],
            0
        );

        $dataset['hits'] = array(
            $this->_status['opcache_statistics']['misses'],
            $this->_status['opcache_statistics']['hits'],
            0,
        );

        $dataset['restarts'] = array(
            $this->_status['opcache_statistics']['oom_restarts'],
            $this->_status['opcache_statistics']['manual_restarts'],
            $this->_status['opcache_statistics']['hash_restarts'],
        );

        if (THOUSAND_SEPARATOR === true) {
            $dataset['TSEP'] = 1;
        } else {
            $dataset['TSEP'] = 0;
        }

        return json_encode($dataset);
    }

    public function getHumanUsedMemory()
    {
        return $this->_size_for_humans($this->getUsedMemory());
    }

    public function getHumanFreeMemory()
    {
        return $this->_size_for_humans($this->getFreeMemory());
    }

    public function getHumanWastedMemory()
    {
        return $this->_size_for_humans($this->getWastedMemory());
    }

    public function getUsedMemory()
    {
        return $this->_status['memory_usage']['used_memory'];
    }

    public function getFreeMemory()
    {
        return $this->_status['memory_usage']['free_memory'];
    }

    public function getWastedMemory()
    {
        return $this->_status['memory_usage']['wasted_memory'];
    }

    public function getWastedMemoryPercentage()
    {
        return number_format($this->_status['memory_usage']['current_wasted_percentage'], 2);
    }

    public function getD3Scripts()
    {
        return $this->_d3Scripts;
    }

    private function _processPartition($value, $name = null)
    {
        if (array_key_exists('size', $value)) {
            return $value;
        }

        $array = array('name' => $name,'children' => array());

        foreach ($value as $k => $v) {
            $array['children'][] = $this->_processPartition($v, $k);
        }

        return $array;
    }

    private function _format_value($value)
    {
        if (THOUSAND_SEPARATOR === true) {
            return number_format($value);
        } else {
            return $value;
        }
    }

    private function _size_for_humans($bytes)
    {
        if ($bytes > 1048576) {
            return sprintf('%.2f&nbsp;MB', $bytes / 1048576);
        } else {
            if ($bytes > 1024) {
                return sprintf('%.2f&nbsp;kB', $bytes / 1024);
            } else {
                return sprintf('%d&nbsp;bytes', $bytes);
            }
        }
    }

    // Borrowed from Laravel
    private function _arrayPset(&$array, $key, $value)
    {
        if (is_null($key)) return $array = $value;
        $keys = explode(DIRECTORY_SEPARATOR, ltrim($key, DIRECTORY_SEPARATOR));
        while (count($keys) > 1) {
            $key = array_shift($keys);
            if ( ! isset($array[$key]) || ! is_array($array[$key])) {
                $array[$key] = array();
            }
            $array =& $array[$key];
        }
        $array[array_shift($keys)] = $value;
        return $array;
    }

}

$dataModel = new OpCacheDataModel();
?>
        <h1><?php echo $dataModel->getPageTitle(); ?></h1>
                        <?php echo $dataModel->getStatusDataRows(); ?>
                        <?php //echo $dataModel->getConfigDataRows(); ?>

                <label for="tab-scripts">Scripts (<?php echo $dataModel->getScriptStatusCount(); ?>)</label>
                            <th width="10%">Hits</th>
                            <th width="20%">Memory</th>
                            <th width="70%">Path</th>
			<?php echo $dataModel->getScriptStatusRows();
echo "\n";
die();
?>

                <label for="tab-visualise">Visualise Partition</label>

        <div id="graph">
            <form>
                <label><input type="radio" name="dataset" value="memory" checked> Memory</label>
                <label><input type="radio" name="dataset" value="keys"> Keys</label>
                <label><input type="radio" name="dataset" value="hits"> Hits</label>
                <label><input type="radio" name="dataset" value="restarts"> Restarts</label>
            </form>

            <div id="stats"></div>
        </div>
    </div>

    <div id="close-partition">&#10006; Close Visualisation</div>
    <div id="partition"></div>

    <script>
        var dataset = <?php echo $dataModel->getGraphDataSetJson(); ?>;

                    "<table><tr><th style='background:#B41F1F;'>Used</th><td><?php echo $dataModel->getHumanUsedMemory()?></td></tr>"+
                    "<tr><th style='background:#1FB437;'>Free</th><td><?php echo $dataModel->getHumanFreeMemory()?></td></tr>"+
                    "<tr><th style='background:#ff7f0e;' rowspan=\"2\">Wasted</th><td><?php echo $dataModel->getHumanWastedMemory()?></td></tr>"+
                    "<tr><td><?php echo $dataModel->getWastedMemoryPercentage()?>%</td></tr></table>"
                );
            } else if (t === "keys") {
                d3.select("#stats").html(
                    "<table><tr><th style='background:#B41F1F;'>Cached keys</th><td>"+format_value(dataset[t][0])+"</td></tr>"+
                    "<tr><th style='background:#1FB437;'>Free Keys</th><td>"+format_value(dataset[t][1])+"</td></tr></table>"
                );
            } else if (t === "hits") {
                d3.select("#stats").html(
                    "<table><tr><th style='background:#B41F1F;'>Misses</th><td>"+format_value(dataset[t][0])+"</td></tr>"+
                    "<tr><th style='background:#1FB437;'>Cache Hits</th><td>"+format_value(dataset[t][1])+"</td></tr></table>"
                );
            } else if (t === "restarts") {
                d3.select("#stats").html(
                    "<table><tr><th style='background:#B41F1F;'>Memory</th><td>"+dataset[t][0]+"</td></tr>"+
                    "<tr><th style='background:#1FB437;'>Manual</th><td>"+dataset[t][1]+"</td></tr>"+
                    "<tr><th style='background:#ff7f0e;'>Keys</th><td>"+dataset[t][2]+"</td></tr></table>"
        root = JSON.parse('<?php echo json_encode($dataModel->getD3Scripts()); ?>');
