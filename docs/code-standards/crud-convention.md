# Hướng dẫn triển khai CRUD

---
## 1. Cấu trúc file

```
module/<Module>/src/
├── Controller/
│   └── <Entity>Controller.php      # chỉ nhận request, gọi Service
├── Service/
│   └── <Entity>Service.php         # business logic
├── Model/<Entity>/
│   ├── <Entity>Model.php           # POPO: getter/setter, getRespXxx()
│   ├── <Entity>Mapper.php          # SQL: search, get, save, delete
│   └── <Entity>Const.php           # constants, extraContent fields
└── Filter/<Entity>/
    ├── <Entity>ListFilter.php
    ├── <Entity>SaveFilter.php       # dùng chung cho create + update (id optional)
    └── <Entity>DeleteFilter.php
```

---

