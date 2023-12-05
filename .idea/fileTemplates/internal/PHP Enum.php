<?php
#parse("PHP File Header.php")

declare(strict_types=1);
#if (${NAMESPACE})
namespace ${NAMESPACE};

#end
enum ${NAME}#if (${BACKED_TYPE}) : ${BACKED_TYPE} #end{

}
