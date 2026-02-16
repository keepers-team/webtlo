-- Добавляем триггер для возможности полного обновления истории сидов.
CREATE TRIGGER IF NOT EXISTS seeders_exists
    BEFORE INSERT ON Seeders
    FOR EACH ROW
    WHEN EXISTS (SELECT 1 FROM Seeders WHERE id = NEW.id)
BEGIN
    UPDATE Seeders
    SET d0  = NEW.d0,
        d1  = NEW.d1,
        d2  = NEW.d2,
        d3  = NEW.d3,
        d4  = NEW.d4,
        d5  = NEW.d5,
        d6  = NEW.d6,
        d7  = NEW.d7,
        d8  = NEW.d8,
        d9  = NEW.d9,
        d10 = NEW.d10,
        d11 = NEW.d11,
        d12 = NEW.d12,
        d13 = NEW.d13,
        d14 = NEW.d14,
        d15 = NEW.d15,
        d16 = NEW.d16,
        d17 = NEW.d17,
        d18 = NEW.d18,
        d19 = NEW.d19,
        d20 = NEW.d20,
        d21 = NEW.d21,
        d22 = NEW.d22,
        d23 = NEW.d23,
        d24 = NEW.d24,
        d25 = NEW.d25,
        d26 = NEW.d26,
        d27 = NEW.d27,
        d28 = NEW.d28,
        d29 = NEW.d29,
        q0  = NEW.q0,
        q1  = NEW.q1,
        q2  = NEW.q2,
        q3  = NEW.q3,
        q4  = NEW.q4,
        q5  = NEW.q5,
        q6  = NEW.q6,
        q7  = NEW.q7,
        q8  = NEW.q8,
        q9  = NEW.q9,
        q10 = NEW.q10,
        q11 = NEW.q11,
        q12 = NEW.q12,
        q13 = NEW.q13,
        q14 = NEW.q14,
        q15 = NEW.q15,
        q16 = NEW.q16,
        q17 = NEW.q17,
        q18 = NEW.q18,
        q19 = NEW.q19,
        q20 = NEW.q20,
        q21 = NEW.q21,
        q22 = NEW.q22,
        q23 = NEW.q23,
        q24 = NEW.q24,
        q25 = NEW.q25,
        q26 = NEW.q26,
        q27 = NEW.q27,
        q28 = NEW.q28,
        q29 = NEW.q29
    WHERE id = NEW.id;

    -- Prevent the actual insert
    SELECT RAISE(IGNORE);
END;


-- Обновляем версию БД.
PRAGMA user_version = 14;
