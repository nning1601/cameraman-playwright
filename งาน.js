

//เขียนโปรแกรมเครื่องคิดเลข โดยมีฟังก์ชันสำหรับการบวก ลบ คูณ และหาร และ จะต้องนำแนวคิด callback ,anonymoun และ arrow มาใช้

//รับค่าตัวเลข 2 ตัวจากผู้ใช้
//และแสดงผลลคำนวน

const readline = require('readline-sync');

// ----------------------
// ฟังก์ชันหลัก รับ callback
// ----------------------
function calculate(num1, num2, operation) {
    return operation(num1, num2);
}

// ----------------------
// ฟังก์ชันคำนวณ
// ----------------------
const add = function(a, b) { return a + b; };           // anonymous function
const subtract = (a, b) => a - b;                      // arrow function
const multiply = function(a, b) { return a * b; };     // anonymous function
const divide = (a, b) => b === 0 ? "หารด้วยศูนย์ไม่ได้" : a / b; // arrow function

// ----------------------
// รับค่าจากผู้ใช้
// ----------------------
let num1 = parseFloat(readline.question("กรอกตัวเลขตัวที่ 1: "));
let num2 = parseFloat(readline.question("กรอกตัวเลขตัวที่ 2: "));

// ----------------------
// เมนูเลือกการคำนวณ
// ----------------------
console.log(`
เลือกการคำนวณ:
1 = บวก
2 = ลบ
3 = คูณ
4 = หาร
5 = ทั้งหมด
`);

let choice = readline.question("เลือก: ");

// ----------------------
// แสดงผลลัพธ์ตามการเลือก
// ----------------------
switch(choice) {
    case "1":
        console.log("ผลบวก: " + calculate(num1, num2, add));
        break;
    case "2":
        console.log("ผลลบ: " + calculate(num1, num2, subtract));
        break;
    case "3":
        console.log("ผลคูณ: " + calculate(num1, num2, multiply));
        break;
    case "4":
        console.log("ผลหาร: " + calculate(num1, num2, divide));
        break;
    case "5":
        console.log("ผลบวก: " + calculate(num1, num2, add));
        console.log("ผลลบ: " + calculate(num1, num2, subtract));
        console.log("ผลคูณ: " + calculate(num1, num2, multiply));
        console.log("ผลหาร: " + calculate(num1, num2, divide));
        break;
    default:
        console.log("เลือกไม่ถูกต้อง");
}
