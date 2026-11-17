<?php
// +----------------------------------------------------------------------
// | TP5.1 模型自动填充语义兼容服务（$auto / $insert / $update）
// +----------------------------------------------------------------------
// | 背景：
// |   TP5.1 的 think\Model 支持三个属性：
// |     protected $auto   = ['update_time'];   // 新增和更新都自动写入
// |     protected $insert = ['create_time'];   // 仅新增时写入
// |     protected $update = [];                // 仅更新时写入
// |
// |   think-orm 4.x **已彻底移除这三个属性**（Model.php 中没有任何
// |   $auto / $insert / $update 的声明与读取）。它改用「option」机制：
// |     AutoWriteData::autoWriteData():
// |       $auto = $this->getOption($update ? 'update' : 'insert', []);
// |   而 option 只能通过 getOptions() 覆盖或 setOption() 注入，
// |   模型上声明 protected $insert = [...] 完全无效。
// |
// |   后果：项目里 100+ 个模型依赖 $auto / $insert 自动补 create_time /
// |   update_time，迁移后这些字段不再填充，写入 int 列得到 0。
// |
// | 解决：
// |   用 Model::maker() 全局钩子，在模型实例化时把 $auto / $insert / $update
// |   属性翻译成框架认识的 option：
// |     insert option = $insert ∪ $auto
// |     update option = $update ∪ $auto
// |   与 TP5.1 语义完全一致。
// |
// |   另外处理「int 时间字段读出被格式化成日期串」的问题：
// |   think-orm 4.x 的 Attribute::getWithAttr() 有一条
// |     (is_int($value) && isTimeAttr($name) && false != getDateFormat())
// |   TP5.1 在 auto_timestamp=false 时读出的是 int，这里要保持一致。
// |
// |   【踩过的坑】最初是把 dateFormat 置为 false 来短路这条分支，结果炸了
// |   「Call to a member function format() on string」：
// |   think-orm 4.x 会把数据库列类型并入类型表（DbConnect::getFields()），
// |   datetime 列会走 think\model\type\DateTime，而 DateTime::data() 里
// |   `if ($format) {...}` 在 format=false 时被整段跳过，
// |   $this->data 仍是原始字符串，输出时对字符串调 ->format() → Fatal。
// |
// |   现在的做法：dateFormat 保持框架默认，改用 withFieldAttr() 动态获取器
// |   把 create_time / update_time 短路成「原值直通」。动态获取器在
// |   getWithAttr() 里优先级最高，既绕过了 int→日期格式化，
// |   又让 datetime 列正常走类型转换。
// +----------------------------------------------------------------------

namespace app\common\service;

use ReflectionProperty;
use think\model\contract\Typeable;
use think\Model;
use think\Service;

class LegacyModelAutoFill extends Service
{
    public function boot(): void
    {
        Model::maker(function (Model $model) {
            $auto   = $this->readProp($model, 'auto');
            $insert = $this->readProp($model, 'insert');
            $update = $this->readProp($model, 'update');

            if ($auto || $insert || $update) {
                $model->setOption('insert', array_values(array_unique(array_merge($insert, $auto))));
                $model->setOption('update', array_values(array_unique(array_merge($update, $auto))));
            }

            // 让 int 时间字段读出时保持 int，同时不破坏 datetime 列。
            //
            // think-orm 4.x 的 Attribute::getWithAttr() 有一条：
            //   is_int($value) && isTimeAttr($name) && false != getDateFormat()
            //     → (new \DateTime())->setTimestamp($value)->format($dateFormat)
            // 它不看数据库列类型，只要是「时间字段 + 值是 int」就会格式化成日期串。
            // 而 TP5.1 只有在 autoWriteTimestamp 为真时才这么做，本项目 auto_timestamp=false，
            // 业务侧拿到的应该是 int（原项目就是这样，前端再自行格式化）。
            //
            // 但「把 dateFormat 设成 false」来绕过它是错的：
            // think-orm 4.x 会把数据库列类型并入类型表（DbConnect::getFields()），
            // datetime 列因此走 think\model\type\DateTime 类型，而 DateTime::data()
            // 里 `if ($format) {...}` 在 format=false 时被整段跳过，$this->data 仍是
            // 原始字符串，输出时 DateTime::format() 对字符串调 ->format() → Fatal。
            //
            // 正解：dateFormat 交给框架默认值，另用动态获取器把 create_time / update_time
            // 短路成「原值直通」—— 优先级高于框架的类型转换分支，且只影响读取输出，
            // 不影响写入（writeTransform）与查询条件。
            $identity = static function ($value) {
                // datetime 列拿到的是 DateTime 类型对象，需要还原成字符串
                return $value instanceof Typeable ? $value->value() : $value;
            };

            $model->withFieldAttr([
                'create_time' => $identity,
                'update_time' => $identity,
            ]);

        });
    }

    /**
     * 读取模型上的 protected 属性（不存在或非数组时返回 []）
     */
    protected function readProp(Model $model, string $name): array
    {
        if (!property_exists($model, $name)) {
            return [];
        }

        $ref = new ReflectionProperty($model, $name);
        $ref->setAccessible(true);
        $value = $ref->getValue($model);

        return is_array($value) ? $value : [];
    }
}
