<?php
/**
 * Created by PhpStorm.
 * User: janbarasek
 * Date: 05.02.18
 * Time: 11:03
 */

namespace OndraKoupil\Csob;


use Tracy\Dumper;
use Tracy\IBarPanel;

class CsobTracyBar implements IBarPanel
{

	/**
	 * @var string[][]
	 */
	private static $requests = [];

	/**
	 * @var Config
	 */
	private static $clientConfig;

	public static function addRequest(int $id, array $request, array $response)
	{
		self::$requests[$id] = [
			'request' => $request,
			'response' => $response,
		];
	}

	public static function setClientConfig(Config $config)
	{
		self::$clientConfig = $config;
	}

	public function getTab()
	{
		$requests = 0;
		$errors = 0;

		foreach (self::$requests as $request) {
			$requests++;
			if (!$request['response']) {
				$errors++;
			}
		}

		return '<span title="ČSOB debugger"><img alt="ČSOB" src="data:image/x-icon;base64,AAABAAEAEBAAAAEAIABoBAAAFgAAACgAAAAQAAAAIAAAAAEAIAAAAAAAQAQAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAHVEMP9sOBn/l3NY/5BrTv+AVTH/iF5G/wAAAACad2b/cDwe/3pMMv8AAAAAoH9n/39TMf+MZUX/iV9J/249K/98TTv/AAAAAAAAAADSw7j/jGRJ/18mBf8AAAAAWh8B/9jLwP+niXj/hFpD/59+Zf98Ty3/l3Va/3RDJv9iLRv/iV9O/wAAAAAAAAAAZi4Y/4BUOP/Itqn/AAAAAFQXAP/d0sn/r5SF/4JXP/+gf2f/ekwq/4lhQv9/Ujj/AAAAAEsOAP9KDgD/m3ts/35TQ/9uPzD/eU5I/wAAAAB9Ukj/XSke/1kjGf8AAAAAkGxa/2s5Iv95Tjn/bDss/wAAAACmjYb/sJaI/wAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAADMmQD/zJkA/8yZAP/MmQD/zJkA/8yZAP/MmQD/zJkA/8yZAP/MmQD/zJkA/8yZAP/MmQD/zJkA/8yZAP/MmQD/58yD/9eoLP/NmQX/zZkA/8yWAP/LlQD/2rJF/+LEbf/cuVT/0KAW/8uVAP/LlgD/zJkA/8yZAP/MmQD/zJkA/wAAAAAAAAAAAAAAAAAAAADx5Lz/9ezS/wAAAADkx3f/379j/+fQk//gwWz/zJkA/8yZAP/MmQD/zJkA/8yZAP8AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAADVpzD/y5YA/8uXAP/LlgT/7dqq/wAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAADo0ZL/ypQA/8yZAP/MmQD/y5cA/9iwQv8AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA6M+P/8qTAP/MmQD/zJkA/8uXAP/YrED/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAADNkw//y5YA/8yaAP/JjgD/6dGb/wAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAANapNv/SoB3/48Jx/wAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA//8AAIEQAAAxAAAAMQAAAIEQAACf/wAAAAAAAAAAAADyAAAA/B8AAPgfAAD4HwAA/B8AAP4/AAD//wAA//8AAA==">'
			. '&nbsp;' . $requests . ' req' . ($errors ? ' + <span style="color:red">' . $errors . ' error' . ($errors === 1 ? '' : 's') . '</span>' : '') . '</span>';
	}

	public function getPanel()
	{
		$countRequests = count(self::$requests);
		$return = '<h1>ČSOB request debugger | ' . $countRequests . ' request' . ($countRequests === 1 ? '' : 's') . '</h1><div class="tracy-inner">';

		if (self::$clientConfig) {
			$return .= Dumper::toHtml(self::$clientConfig);
		} else {
			$return .= '<p style="color: red; font-size: 16pt">Can not load client configuration!!!</p>';
		}

		$return .= '<table>';

		$iterator = 1;
		foreach (self::$requests as $request) {
			$return .= '<tr>
				<th>' . ($iterator++) . ($request['response'] ? '' : '<br><span style="color:red">ERROR</span>') . '</th>
				<td>
					<table><tr><th>Request</th></tr></table>
					' . Dumper::toHtml($request['request']) . '
					<table><tr><th>Response</th></tr></table>
					' . Dumper::toHtml($request['response']) . '
				</td>
			</tr><tr><th>&nbsp;</th><th>&nbsp;</th></tr>';
		}

		$return .= '</table></div>';

		return $return;
	}

}